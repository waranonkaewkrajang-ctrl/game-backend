<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Write actions from the unified admin act as the LOGGED-IN LINE admin staff member.
 *
 * - Header X-Acting-Staff = short-lived assertion signed by the NestJS admin API:
 *     base64url(json{sid, u, p:[permissions], iat, exp}) "." hex(HMAC-SHA256(payload, key))
 *   key = the SHA-256 hash of the service token that authenticated this request (Laravel only stores hashes,
 *   Nest holds the token) → no extra secret to configure; a stolen assertion is useless without the token.
 * - Max lifetime 120 s, clock skew 30 s. Writes are additionally protected by Idempotency-Key.
 * - Laravel checks the permission itself (defence in depth): every write route maps to one game:* permission
 *   (ROUTE_PERMISSIONS, fail closed for an unmapped route) and the assertion must contain it.
 * - Each staff member gets their OWN game `admins` row, created on first use (username "line-staff-<id>",
 *   random unusable password, role staff) and mapped in internal_staff_admins. It becomes $request->user(), so
 *   existing controllers record approved_by / processed_by for the real person. Historical admin accounts are
 *   never reused or impersonated, and numeric ids from the two databases are never assumed to match.
 * Runs after AuthenticateInternalService (service token + IP already verified).
 */
class InternalActingAdmin
{
    public const MAX_TTL = 120;

    public const SKEW = 30;

    /** first path segment after api/internal/admin/ → required permission (exact path overrides first) */
    public const ROUTE_PERMISSIONS = [
        'game-catalog/games-by-keys' => 'game:games:read',
        'ranks/upload-image'         => 'game:settings:write',
        'deposits'                   => 'game:finance:write',
        'withdrawals'                => 'game:finance:write',
        'unmatched-deposits'         => 'game:finance:write',
        'users'                      => 'game:members:write',
        'bank-changes'               => 'game:members:write',
        'games'                      => 'game:games:write',
        'promotions'                 => 'game:promotions:write',
        'popups'                     => 'game:promotions:write',
        'activities'                 => 'game:promotions:write',
        'banners'                    => 'game:promotions:write',
        'spin-wheel'                 => 'game:promotions:write',
        'settings'                   => 'game:settings:write',
        'theme'                      => 'game:settings:write',
        'admins'                     => 'game:admins:write',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $claims = self::verify(
            (string) $request->header('X-Acting-Staff', ''),
            (string) $request->attributes->get('internal_token_hash', ''),
        );
        if ($claims === null) {
            return response()->json(['status' => 'error', 'message' => 'ยืนยันตัวตนพนักงานไม่ผ่าน (หมดอายุหรือไม่ถูกต้อง)'], 403);
        }

        $required = self::requiredPermission($request);
        if ($required === null || ! in_array($required, $claims['p'], true)) {
            return response()->json(['status' => 'error', 'message' => 'ไม่มีสิทธิ์ทำรายการนี้'], 403);
        }

        $admin = self::adminFor($claims['sid'], $claims['u']);
        if (! $admin || ! $admin->is_active) {
            return response()->json(['status' => 'error', 'message' => 'บัญชีแอดมินเกมของพนักงานนี้ถูกปิดไว้'], 403);
        }

        Auth::setUser($admin);
        $request->setUserResolver(fn () => $admin);

        Log::channel(config('services.internal_admin.log_channel', 'stack'))->info('internal-admin write', [
            'method'     => $request->getMethod(),
            'path'       => $request->path(),
            'admin_id'   => $admin->id,
            'line_staff' => $claims['sid'].':'.$claims['u'],
            'permission' => $required,
            'key'        => substr((string) $request->header('Idempotency-Key', ''), 0, 100),
        ]);

        return $next($request);
    }

    /** @return array{sid:int,u:string,p:array<int,string>}|null */
    public static function verify(string $assertion, string $key): ?array
    {
        if ($key === '' || strlen($assertion) > 4096 || substr_count($assertion, '.') !== 1) {
            return null;
        }
        [$payload, $sig] = explode('.', $assertion);
        if (! hash_equals(hash_hmac('sha256', $payload, $key), strtolower($sig))) {
            return null;
        }
        $json = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (! is_array($json)) {
            return null;
        }
        $sid = $json['sid'] ?? null;
        $iat = $json['iat'] ?? null;
        $exp = $json['exp'] ?? null;
        $now = time();
        if (! is_int($sid) || $sid < 1 || ! is_int($iat) || ! is_int($exp)) {
            return null;
        }
        if ($iat > $now + self::SKEW || $exp < $now - self::SKEW || $exp - $iat > self::MAX_TTL || $exp < $iat) {
            return null;
        }
        $perms = array_values(array_filter((array) ($json['p'] ?? []), fn ($p) => is_string($p) && str_starts_with($p, 'game:')));
        $user = substr(preg_replace('/[^\w.\-@]/u', '', (string) ($json['u'] ?? '')), 0, 60);

        return ['sid' => $sid, 'u' => $user, 'p' => $perms];
    }

    public static function requiredPermission(Request $request): ?string
    {
        $route = $request->route();
        $uri = $route ? $route->uri() : $request->path();
        $rest = preg_replace('#^api/internal/admin/#', '', $uri);
        if (isset(self::ROUTE_PERMISSIONS[$rest])) {
            return self::ROUTE_PERMISSIONS[$rest];
        }

        return self::ROUTE_PERMISSIONS[explode('/', $rest)[0]] ?? null;
    }

    /** The staff member's own game admin account — created on first use, never a historical one. */
    public static function adminFor(int $staffId, string $username): ?Admin
    {
        $map = DB::table('internal_staff_admins')->where('line_staff_id', $staffId)->first();
        if ($map) {
            DB::table('internal_staff_admins')->where('id', $map->id)
                ->update(['line_username' => $username, 'last_seen_at' => now(), 'updated_at' => now()]);

            return Admin::query()->whereKey($map->admin_id)->first();
        }

        try {
            return DB::transaction(function () use ($staffId, $username) {
                $admin = Admin::create([
                    'username'    => 'line-staff-'.$staffId,
                    'password'    => Hash::make(Str::random(64)), // nobody knows it → cannot log in to the old panel
                    'name'        => Str::limit('LINE: '.($username !== '' ? $username : '#'.$staffId), 100, ''),
                    'role'        => 'staff',
                    'permissions' => [],
                    'is_active'   => true,
                ]);
                DB::table('internal_staff_admins')->insert([
                    'line_staff_id' => $staffId,
                    'admin_id'      => $admin->id,
                    'line_username' => $username,
                    'last_seen_at'  => now(),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                return $admin;
            });
        } catch (QueryException $e) {
            // two first writes at the same moment → the other one created it; a clashing historical username → refuse
            $map = DB::table('internal_staff_admins')->where('line_staff_id', $staffId)->first();

            return $map ? Admin::query()->whereKey($map->admin_id)->first() : null;
        }
    }
}
