<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RewardController extends Controller
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    public function summary(Request $request)
    {
        $user = $request->user();
        $cashbackPending = Reward::pendingAmount($user->id, 'cashback');
        $referralPending = Reward::pendingAmount($user->id, 'referral');
        $cashbackClaimed = (float) Reward::where('user_id', $user->id)
            ->where('type', 'cashback')->where('status', 'claimed')->sum('amount');
        $referralClaimed = (float) Reward::where('user_id', $user->id)
            ->where('type', 'referral')->where('status', 'claimed')->sum('amount');

        return response()->json([
            'status' => 'success',
            'data' => [
                'cashback' => ['pending' => $cashbackPending, 'claimed' => $cashbackClaimed],
                'referral' => ['pending' => $referralPending, 'claimed' => $referralClaimed],
            ],
        ]);
    }

    public function claimCashback(Request $request)
    {
        return $this->claimReward($request->user(), 'cashback');
    }

    public function claimReferral(Request $request)
    {
        return $this->claimReward($request->user(), 'referral');
    }

    public function history(Request $request)
    {
        $query = Reward::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc');
        if ($request->filled('type')) $query->where('type', $request->type);
        if ($request->filled('status')) $query->where('status', $request->status);

        return response()->json(['status' => 'success', 'data' => $query->paginate(20)]);
    }

    private function claimReward($user, string $type)
    {
        $typeName = $type === 'cashback' ? 'ยอดเสีย' : 'ค่าแนะนำเพื่อน';

        return DB::transaction(function () use ($user, $type, $typeName) {
            $pendingRewards = Reward::where('user_id', $user->id)
                ->where('type', $type)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            if ($pendingRewards->isEmpty()) {
                return response()->json(['status' => 'error', 'message' => "ไม่มี{$typeName}ที่รอรับ"], 400);
            }

            $totalAmount = $pendingRewards->sum('amount');

            Reward::where('user_id', $user->id)
                ->where('type', $type)
                ->where('status', 'pending')
                ->update(['status' => 'claimed', 'claimed_at' => now()]);

            $this->walletService->addBonus(
                $user, $totalAmount,
                "รับ{$typeName} ฿" . number_format($totalAmount, 2),
                ['type' => "claim_{$type}", 'reward_count' => $pendingRewards->count()]
            );

            return response()->json([
                'status' => 'success',
                'message' => "รับ{$typeName}สำเร็จ ฿" . number_format($totalAmount, 2),
                'data' => [
                    'amount' => $totalAmount,
                    'count' => $pendingRewards->count(),
                    'balance' => $this->walletService->getBalance($user),
                ],
            ]);
        });
    }
}