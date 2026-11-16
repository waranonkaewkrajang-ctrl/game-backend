<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * รายงานกลุ่มลูกค้าตามยอดการเงิน (CRM กฎ C) — อ่านอย่างเดียว เรียกเมื่อแอดมินกดดูเท่านั้น (ไม่ใช่ sync ตามรอบ)
 * คำนวณจากรายการที่ status = 'approved' (สูตรเดียวกับ MemberInfoController) — ไม่ใช้ wallets.total_* ที่ไม่แม่น
 */
class CrmSegmentController extends Controller
{
    public function withdrawGtDeposit(Request $request): JsonResponse
    {
        $days = max(1, min(365, (int) $request->query('period_days', 30)));
        $limit = max(1, min(500, (int) $request->query('limit', 200)));
        $from = now()->subDays($days);

        $dep = DB::table('deposits')->where('status', 'approved')->where('approved_at', '>=', $from)
            ->groupBy('user_id')->selectRaw('user_id, SUM(amount) total');
        $wit = DB::table('withdrawals')->where('status', 'approved')->where('approved_at', '>=', $from)
            ->groupBy('user_id')->selectRaw('user_id, SUM(amount) total');

        $base = DB::query()->fromSub($wit, 'w')
            ->leftJoinSub($dep, 'd', 'd.user_id', '=', 'w.user_id')
            ->whereRaw('w.total > COALESCE(d.total, 0)');

        $count = (clone $base)->count();
        $rows = (clone $base)
            ->join('users as u', 'u.id', '=', 'w.user_id')
            ->selectRaw('w.user_id as member_id, u.username, COALESCE(d.total,0) as deposit_total, w.total as withdraw_total, (w.total - COALESCE(d.total,0)) as net')
            ->orderByDesc('net')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'member_id'      => (int) $r->member_id,
                'username'       => $r->username,
                'deposit_total'  => round((float) $r->deposit_total, 2),
                'withdraw_total' => round((float) $r->withdraw_total, 2),
                'net'            => round((float) $r->net, 2),
            ]);

        return response()->json(['status' => 'success', 'data' => [
            'key'          => 'withdraw_gt_deposit',
            'period_days'  => $days,
            'generated_at' => now()->toIso8601String(),
            'count'        => $count,
            'members'      => $rows,
        ]]);
    }
}
