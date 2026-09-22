<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AMBService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminTopbarController extends Controller
{
    /**
     * ข้อมูลแถบบนหลังบ้าน: ลูกค้าออนไลน์ / พนักงานออนไลน์ / เครดิต agent
     * "ออนไลน์" = เรียก API ภายใน 5 นาทีล่าสุด (อ่านจาก last_used_at ของ Sanctum token)
     */
    public function index(Request $request, AMBService $amb): JsonResponse
    {
        $since = now()->subMinutes(5);

        $onlineUsers = DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\User::class)
            ->where('last_used_at', '>=', $since)
            ->distinct()
            ->count('tokenable_id');

        $users = DB::table('personal_access_tokens as t')
            ->join('users as u', 'u.id', '=', 't.tokenable_id')
            ->where('t.tokenable_type', \App\Models\User::class)
            ->where('t.last_used_at', '>=', $since)
            ->groupBy('u.id', 'u.username')
            ->selectRaw('u.id, u.username, MAX(t.last_used_at) AS last_seen')
            ->orderByDesc('last_seen')
            ->limit(100)
            ->get();

        $staff = DB::table('personal_access_tokens as t')
            ->join('admins as a', 'a.id', '=', 't.tokenable_id')
            ->where('t.tokenable_type', \App\Models\Admin::class)
            ->where('t.last_used_at', '>=', $since)
            ->groupBy('a.id', 'a.username')
            ->selectRaw('a.id, a.username, MAX(t.last_used_at) AS last_seen')
            ->orderByDesc('last_seen')
            ->get();

        if ($request->boolean('refresh')) {
            Cache::forget('topbar_agent_credit');
        }

        // เครดิต AMB — cache 30 วินาที กันยิงค่ายถี่เกิน
        $credit = Cache::remember('topbar_agent_credit', 30, function () use ($amb) {
            try {
                $r = $amb->getAgentCredit();
                if (($r['code'] ?? 9999) !== 0) return null;
                $d = $r['data'] ?? null;
                if (is_numeric($d)) return (float) $d;
                if (is_array($d) && isset($d['credit']) && is_numeric($d['credit'])) {
                    return (float) $d['credit'];
                }
                return null;
            } catch (\Throwable $e) {
                return null;
            }
        });

        return response()->json([
            'status' => 'success',
            'data'   => [
                'online_users' => $onlineUsers,
                'users'        => $users,
                'online_staff' => $staff->count(),
                'staff'        => $staff,
                'agent_credit' => $credit,
                'updated_at'   => now()->toIso8601String(),
            ],
        ]);
    }
}