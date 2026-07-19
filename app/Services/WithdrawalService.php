<?php

namespace App\Services;

use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Str;

class WithdrawalService
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    public function createRequest(User $user, float $amount): Withdrawal
    {
        $hasPending = Withdrawal::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($hasPending) {
            throw new \Exception('มีรายการถอนที่รอดำเนินการอยู่แล้ว');
        }

        $this->checkTurnoverRequirement($user);

        $wallet = $user->wallet;
        if (!$wallet || !$wallet->hasEnough($amount)) {
            throw new \Exception('ยอดเงินไม่เพียงพอ');
        }

        $balanceBefore = $wallet->balance;

        $this->walletService->withdraw(
            $user,
            $amount,
            'ถอนเงิน (รอตรวจสอบ)',
            ['type' => 'withdrawal_request']
        );

        $balanceAfter = $user->wallet->fresh()->balance;

        return Withdrawal::create([
            'user_id'        => $user->id,
            'reference_id'   => 'WDR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(10)),
            'amount'         => $amount,
            'to_bank'        => $user->bank_code,
            'to_account'     => $user->bank_account,
            'to_name'        => $user->bank_name,
            'status'         => 'pending',
            'balance_before' => $balanceBefore,
            'balance_after'  => $balanceAfter,
        ]);
    }

    public function approve(Withdrawal $withdrawal, int $adminId): Withdrawal
    {
        if (!in_array($withdrawal->status, ['pending', 'processing'])) {
            throw new \Exception('รายการนี้ถูกดำเนินการแล้ว');
        }

        $withdrawal->update([
            'status'      => 'approved',
            'approved_by' => $adminId,
            'approved_at' => now(),
        ]);

        return $withdrawal->fresh();
    }

    public function reject(Withdrawal $withdrawal, int $adminId, string $reason): Withdrawal
    {
        if (!in_array($withdrawal->status, ['pending', 'processing'])) {
            throw new \Exception('รายการนี้ถูกดำเนินการแล้ว');
        }

        $this->walletService->deposit(
            $withdrawal->user,
            $withdrawal->amount,
            'คืนเงินถอน (ปฏิเสธ) #' . $withdrawal->reference_id,
            ['withdrawal_id' => $withdrawal->id, 'reason' => $reason],
            $adminId
        );

        $withdrawal->update([
            'status'        => 'rejected',
            'reject_reason' => $reason,
            'approved_by'   => $adminId,
            'approved_at'   => now(),
        ]);

        return $withdrawal->fresh();
    }

    private function checkTurnoverRequirement(User $user): void
    {
        $activeClaim = $user->promotionClaims()
            ->where('status', 'active')
            ->where('turnover_completed', false)
            ->first();

        if ($activeClaim) {
            $remaining = bcsub($activeClaim->turnover_required, $activeClaim->turnover_current, 2);
            throw new \Exception(
                'ยังทำ turnover ไม่ครบ (เหลืออีก ' . number_format($remaining, 2) . ' บาท)'
            );
        }
    }
}