<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StuckBet;
use Illuminate\Http\JsonResponse;

/**
 * ดูรายการเดิมพันค้าง — อ่านอย่างเดียว ไม่มีการคืนเงิน
 */
class AdminStuckBetController extends Controller
{
    /** รายการค้างของสมาชิกคนเดียว */
    public function byUser(int $userId): JsonResponse
    {
        $rows = StuckBet::where('user_id', $userId)
            ->orderByDesc('bet_at')
            ->get()
            ->map(fn ($s) => [
                'id'         => $s->id,
                'provider'   => $s->provider,
                'round_id'   => $s->round_id,
                'txn_id'     => $s->txn_id,
                'bet_amount' => (float) $s->bet_amount,
                'amb_payout' => $s->amb_payout !== null ? (float) $s->amb_payout : null,
                'amb_status' => $s->amb_status,
                'status'     => $s->status,
                'suggested'  => $s->suggestedRefund(),
                'bet_at'     => optional($s->bet_at)->format('d/m/Y H:i'),
            ]);

        $pending = $rows->where('status', 'pending');

        return response()->json([
            'status'  => 'success',
            'data'    => $rows->values(),
            'summary' => [
                'pending_count'  => $pending->count(),
                'pending_amount' => (float) $pending->sum('bet_amount'),
            ],
        ]);
    }
}