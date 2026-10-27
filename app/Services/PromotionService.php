<?php

namespace App\Services;

use App\Models\Deposit;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\User;

class PromotionService
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    public function canClaim(User $user, Promotion $promotion): array
    {
        if (!$promotion->is_active) {
            return ['can_claim' => false, 'reason' => 'โปรโมชันนี้ปิดแล้ว'];
        }

        if ($promotion->start_at && now()->lt($promotion->start_at)) {
            return ['can_claim' => false, 'reason' => 'โปรโมชันยังไม่เริ่ม'];
        }

        if ($promotion->end_at && now()->gt($promotion->end_at)) {
            return ['can_claim' => false, 'reason' => 'โปรโมชันหมดอายุแล้ว'];
        }

        $claimCount = PromotionClaim::where('user_id', $user->id)
            ->where('promotion_id', $promotion->id)
            ->count();

        if ($claimCount >= $promotion->claims_per_user) {
            return ['can_claim' => false, 'reason' => 'คุณรับโปรโมชันนี้ครบแล้ว'];
        }

        if ($promotion->max_claims) {
            $totalClaims = PromotionClaim::where('promotion_id', $promotion->id)->count();
            if ($totalClaims >= $promotion->max_claims) {
                return ['can_claim' => false, 'reason' => 'โปรโมชันถูกรับครบแล้ว'];
            }
        }

        $hasActive = PromotionClaim::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('turnover_completed', false)
            ->exists();

        if ($hasActive) {
            return ['can_claim' => false, 'reason' => 'มีโปรโมชันที่ยังทำ turnover ไม่ครบอยู่'];
        }

        return ['can_claim' => true, 'reason' => null];
    }

    public function applyBonus(User $user, Deposit $deposit): ?PromotionClaim
    {
        $promotion = $deposit->promotion;
        if (!$promotion) {
            return null;
        }

        $check = $this->canClaim($user, $promotion);
        if (!$check['can_claim']) {
            return null;
        }

        if ($deposit->amount < $promotion->min_deposit) {
            return null;
        }

        $bonusAmount = bcmul($deposit->amount, bcdiv($promotion->bonus_percent, 100, 4), 2);

        if ($promotion->max_bonus > 0 && $bonusAmount > $promotion->max_bonus) {
            $bonusAmount = $promotion->max_bonus;
        }

        if ($bonusAmount <= 0) {
            return null;
        }

        $turnoverRequired = bcmul(
            bcadd($deposit->amount, $bonusAmount, 2),
            $promotion->turnover_multiplier,
            2
        );

        // เติมโบนัส + สร้าง claim เดียวใน transaction เดียว (เดิมสร้าง claim ซ้อน 2 ก้อน)
        $this->walletService->addBonus(
            $user,
            (float) $bonusAmount,
            "โบนัส: {$promotion->title}",
            ['promotion_id' => $promotion->id, 'deposit_id' => $deposit->id],
            null,
            [
                'type'                => 'deposit_bonus',
                'promotion_id'        => $promotion->id,
                'deposit_id'          => $deposit->id,
                'turnover_multiplier' => (float) $promotion->turnover_multiplier,
                'turnover_required'   => $turnoverRequired,
                'allowed_categories'  => $promotion->allowed_categories ?? null,
                'allowed_providers'   => $promotion->allowed_providers ?? null,
                'allowed_games'       => $promotion->allowed_games ?? null,
            ],
        );

        return PromotionClaim::where('deposit_id', $deposit->id)
            ->where('promotion_id', $promotion->id)
            ->latest('id')
            ->first();
    }
}