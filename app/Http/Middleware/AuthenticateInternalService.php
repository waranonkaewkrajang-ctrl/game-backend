<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service-to-service auth for the unified admin (NestJS) — READ-ONLY internal admin API.
 *
 * - Token in "Authorization: Bearer <token>" only (never query/body). Stored as SHA-256 hashes in env
 *   (INTERNAL_ADMIN_TOKEN_HASHES, comma separated) → no DB schema change, rotate by adding a new hash first.
 * - Optional IP allowlist (INTERNAL_ADMIN_ALLOWED_IPS, comma separated).
 * - GET/HEAD by default. Write methods only when INTERNAL_ADMIN_WRITES_ENABLED=true AND the matched route is
 *   in the write group (middleware internal.actor + internal.idempotent → real game admin + Idempotency-Key).
 * - Logs who acted (X-Acting-Admin from the unified admin) without logging the token.
 */
class AuthenticateInternalService
{
    public function handle(Request $request, Closure $next): Response
    {
        $hashes = array_values(array_filter(array_map('trim', explode(',', (string) config('services.internal_admin.token_hashes')))));
        $plain = (string) $request->bearerToken();
        if ($hashes === [] || strlen($plain) < 32) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $given = hash('sha256', $plain);
        $valid = false;
        foreach ($hashes as $hash) {
            if (hash_equals(strtolower($hash), $given)) {
                $valid = true;
            }
        }
        if (! $valid) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) config('services.internal_admin.allowed_ips')))));
        if ($allowed !== [] && ! in_array($request->ip(), $allowed, true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            $route = $request->route();
            $mw = $route ? $route->gatherMiddleware() : [];
            $isWriteRoute = in_array('internal.actor', $mw, true) && in_array('internal.idempotent', $mw, true);
            if (! $isWriteRoute) {
                return response()->json(['message' => 'Method not allowed'], 405);
            }
            if (! config('services.internal_admin.writes_enabled')) {
                return response()->json(['status' => 'error', 'message' => 'การแก้ไขข้อมูลจากหลังบ้านรวมยังปิดอยู่ (INTERNAL_ADMIN_WRITES_ENABLED)'], 403);
            }
        }

        $actor = substr(preg_replace('/[^\w:.\-@]/u', '', (string) $request->header('X-Acting-Admin', '')), 0, 80);
        Log::channel(config('services.internal_admin.log_channel', 'stack'))->info('internal-admin read', [
            'path'  => $request->path(),
            'actor' => $actor,
            'ip'    => $request->ip(),
        ]);

        return $next($request);
    }
}
