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
            return ['can_claim' => false, 'reason' => 'โปรโมชันนี้ปิดแล้ว', 'code' => 'inactive'];
        }

        if ($promotion->start_at && now()->lt($promotion->start_at)) {
            return ['can_claim' => false, 'reason' => 'โปรโมชันยังไม่เริ่ม', 'code' => 'not_started'];
        }

        if ($promotion->end_at && now()->gt($promotion->end_at)) {
            return ['can_claim' => false, 'reason' => 'โปรโมชันหมดอายุแล้ว', 'code' => 'ended'];
        }

        $claimCount = PromotionClaim::where('user_id', $user->id)
            ->where('promotion_id', $promotion->id)
            ->count();

        if ($claimCount >= $promotion->claims_per_user) {
            return ['can_claim' => false, 'reason' => 'คุณรับโปรโมชันนี้ครบแล้ว', 'code' => 'user_limit'];
        }

        if ($promotion->max_claims) {
            $totalClaims = PromotionClaim::where('promotion_id', $promotion->id)->count();
            if ($totalClaims >= $promotion->max_claims) {
                return ['can_claim' => false, 'reason' => 'โปรโมชันถูกรับครบแล้ว', 'code' => 'global_limit'];
            }
        }

        $hasActive = PromotionClaim::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('turnover_completed', false)
            ->exists();

        if ($hasActive) {
            return ['can_claim' => false, 'reason' => 'มีโปรโมชันที่ยังทำ turnover ไม่ครบอยู่', 'code' => 'active_turnover'];
        }

        return ['can_claim' => true, 'reason' => null, 'code' => null];
    }

    public function applyBonus(User $user, Deposit $deposit): ?PromotionClaim
    {
        return $this->applyBonusWithReason($user, $deposit)['claim'];
    }

    /**
     * เหมือน applyBonus แต่บอกเหตุผลเมื่อข้าม (ไว้เขียน log)
     *
     * @return array{granted: bool, code: ?string, reason: ?string, bonus: ?string, turnover: ?string, claim: ?PromotionClaim}
     */
    public function applyBonusWithReason(User $user, Deposit $deposit): array
    {
        $skip = fn (string $code, string $reason) => [
            'granted' => false, 'code' => $code, 'reason' => $reason, 'bonus' => null, 'turnover' => null, 'claim' => null,
        ];

        $promotion = $deposit->promotion;
        if (!$promotion) {
            return $skip('promotion_missing', 'ไม่พบโปรโมชัน (อาจถูกลบไปแล้ว)');
        }

        $check = $this->canClaim($user, $promotion);
        if (!$check['can_claim']) {
            return $skip($check['code'], $check['reason']);
        }

        if ($deposit->amount < $promotion->min_deposit) {
            return $skip('below_min_deposit', "ยอดฝากต่ำกว่าขั้นต่ำของโปร ({$promotion->min_deposit})");
        }

        $bonusAmount = bcmul($deposit->amount, bcdiv($promotion->bonus_percent, 100, 4), 2);

        if ($promotion->max_bonus > 0 && $bonusAmount > $promotion->max_bonus) {
            $bonusAmount = $promotion->max_bonus;
        }

        if ($bonusAmount <= 0) {
            return $skip('zero_bonus', 'คำนวณโบนัสได้ 0');
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

        $claim = PromotionClaim::where('deposit_id', $deposit->id)
            ->where('promotion_id', $promotion->id)
            ->latest('id')
            ->first();

        return [
            'granted'  => true,
            'code'     => null,
            'reason'   => null,
            'bonus'    => (string) $bonusAmount,
            'turnover' => (string) $turnoverRequired,
            'claim'    => $claim,
        ];
    }
}