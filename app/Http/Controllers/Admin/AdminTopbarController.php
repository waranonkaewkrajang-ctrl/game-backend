<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AMBService;
use App\Services\CustomerPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminTopbarController extends Controller
{
    /**
     * ข้อมูลแถบบนหลังบ้าน: ลูกค้าออนไลน์ / พนักงานออนไลน์ / เครดิต agent
     * "ออนไลน์" = เรียก API ภายใน N นาทีล่าสุด (last_used_at ของ Sanctum token) — ดู CustomerPresence
     * ลูกค้าแต่ละคนมี avatar_url = รูปเดียวกับที่หน้าเว็บลูกค้าแสดง (CustomerAvatar)
     */
    public function index(Request $request, AMBService $amb, CustomerPresence $presence): JsonResponse
    {
        $since = now()->subMinutes($presence->minutes());

        $onlineUsers = $presence->onlineCount();
        $users = $presence->onlineMembers(100);

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

        // เครดิต AMB — cache 30 วินาที กันยิงค่ายถี่เกิน (ค่าเดิม) · agent_credit_at = เวลาที่ถามค่ายจริง
        $credit = Cache::remember('topbar_agent_credit', 30, function () use ($amb) {
            Cache::put('topbar_agent_credit_at', now()->toIso8601String(), 60);
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
                'online_users'    => $onlineUsers,
                'users'           => $users,
                'online_staff'    => $staff->count(),
                'staff'           => $staff,
                'agent_credit'    => $credit,
                'agent_credit_at' => $credit === null ? null : Cache::get('topbar_agent_credit_at'),
                'online_minutes'  => $presence->minutes(),
                'updated_at'      => now()->toIso8601String(),
            ],
        ]);
    }
}
