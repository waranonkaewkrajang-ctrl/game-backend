<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Write actions from the unified admin act AS a real game admin account.
 *
 * - X-Acting-Admin-Id = admins.id that the unified-admin staff member is linked to (set by a super admin).
 * - The account must exist and be active (same rule as EnsureAdmin for the old admin panel).
 * - The admin becomes $request->user(), so existing controllers record approved_by / processed_by exactly as
 *   when the person uses the old admin panel. No controller code changes.
 * Runs after AuthenticateInternalService (service token + IP already verified).
 */
class InternalActingAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->header('X-Acting-Admin-Id', '');
        if (! ctype_digit($id) || (int) $id < 1) {
            return response()->json(['status' => 'error', 'message' => 'บัญชีนี้ยังไม่ได้ผูกกับแอดมินระบบเกม'], 403);
        }

        $admin = Admin::query()->whereKey((int) $id)->first();
        if (! $admin || ! $admin->is_active) {
            return response()->json(['status' => 'error', 'message' => 'บัญชีแอดมินเกมที่ผูกไว้ถูกปิดหรือไม่มีอยู่'], 403);
        }

        Auth::setUser($admin);
        $request->setUserResolver(fn () => $admin);

        Log::channel(config('services.internal_admin.log_channel', 'stack'))->info('internal-admin write', [
            'method'   => $request->getMethod(),
            'path'     => $request->path(),
            'admin_id' => $admin->id,
            'actor'    => substr(preg_replace('/[^\w:.\-@]/u', '', (string) $request->header('X-Acting-Admin', '')), 0, 80),
            'key'      => substr((string) $request->header('Idempotency-Key', ''), 0, 100),
        ]);

        return $next($request);
    }
}
