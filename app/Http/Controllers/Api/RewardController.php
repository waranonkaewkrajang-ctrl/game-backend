<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use App\Models\Setting;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RewardController extends Controller
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    /**
     * ดูยอดรอรับ (เฉพาะที่ยังไม่หมดอายุ)
     */
    public function summary(Request $request)
    {
        $user = $request->user();
        $expireDays = (int) Setting::getValue('reward_expire_days', 0);

        // ยอด pending ที่ยังไม่หมดอายุ
        $cashbackPending = $this->getPendingAmount($user->id, 'cashback', $expireDays);
        $referralPending = $this->getPendingAmount($user->id, 'referral', $expireDays);

        // ยอดที่รับแล้ว
        $cashbackClaimed = (float) Reward::where('user_id', $user->id)
            ->where('type', 'cashback')->where('status', 'claimed')->sum('amount');
        $referralClaimed = (float) Reward::where('user_id', $user->id)
            ->where('type', 'referral')->where('status', 'claimed')->sum('amount');

        // ยอดที่หมดอายุ
        $cashbackExpired = (float) Reward::where('user_id', $user->id)
            ->where('type', 'cashback')->where('status', 'expired')->sum('amount');
        $referralExpired = (float) Reward::where('user_id', $user->id)
            ->where('type', 'referral')->where('status', 'expired')->sum('amount');

        return response()->json([
            'status' => 'success',
            'data' => [
                'cashback' => ['pending' => $cashbackPending, 'claimed' => $cashbackClaimed, 'expired' => $cashbackExpired],
                'referral' => ['pending' => $referralPending, 'claimed' => $referralClaimed, 'expired' => $referralExpired],
                'expire_days' => $expireDays,
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

    /**
     * กดรับรางวัล (เฉพาะที่ยังไม่หมดอายุ)
     */
    private function claimReward($user, string $type)
    {
        $typeName = $type === 'cashback' ? 'ยอดเสีย' : 'ค่าแนะนำเพื่อน';
        $expireDays = (int) Setting::getValue('reward_expire_days', 0);

        return DB::transaction(function () use ($user, $type, $typeName, $expireDays) {
            $query = Reward::where('user_id', $user->id)
                ->where('type', $type)
                ->where('status', 'pending')
                ->lockForUpdate();

            // ถ้าตั้งวันหมดอายุ → รับได้เฉพาะที่ยังไม่หมดอายุ
            if ($expireDays > 0) {
                $query->where('created_at', '>=', Carbon::now()->subDays($expireDays));
            }

            $pendingRewards = $query->get();

            if ($pendingRewards->isEmpty()) {
                return response()->json(['status' => 'error', 'message' => "ไม่มี{$typeName}ที่รอรับ"], 400);
            }

            $totalAmount = $pendingRewards->sum('amount');

            // อัปเดตเฉพาะรายการที่ยังไม่หมดอายุ
            Reward::whereIn('id', $pendingRewards->pluck('id'))
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

    /**
     * ยอด pending ที่ยังไม่หมดอายุ
     */
    private function getPendingAmount(int $userId, string $type, int $expireDays): float
    {
        $query = Reward::where('user_id', $userId)
            ->where('type', $type)
            ->where('status', 'pending');

        if ($expireDays > 0) {
            $query->where('created_at', '>=', Carbon::now()->subDays($expireDays));
        }

        return (float) $query->sum('amount');
    }
}