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
 * - GET/HEAD only, regardless of the route list.
 * - Logs who acted (X-Acting-Admin from the unified admin) without logging the token.
 */
class AuthenticateInternalService
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return response()->json(['message' => 'Method not allowed'], 405);
        }

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

        $actor = substr(preg_replace('/[^\w:.\-@]/u', '', (string) $request->header('X-Acting-Admin', '')), 0, 80);
        Log::channel(config('services.internal_admin.log_channel', 'stack'))->info('internal-admin read', [
            'path'  => $request->path(),
            'actor' => $actor,
            'ip'    => $request->ip(),
        ]);

        return $next($request);
    }
}
