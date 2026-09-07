<?php

namespace App\Services;

use App\Models\Setting;
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
        $minWithdraw = (float) Setting::getValue('min_withdraw', 100);
        $maxWithdraw = (float) Setting::getValue('max_withdraw', 200000);

        if ($amount < $minWithdraw) {
            throw new \Exception("ถอนขั้นต่ำ {$minWithdraw} บาท");
        }
        if ($amount > $maxWithdraw) {
            throw new \Exception("ถอนสูงสุด {$maxWithdraw} บาท");
        }

        $wallet = $user->wallet;
        if (!$wallet || $wallet->balance < $amount) {
            throw new \Exception('ยอดเงินไม่เพียงพอ');
        }

        // =====================================================
        //  🆕 เช็คเทิร์นโอเวอร์ก่อนอนุญาตถอน
        // =====================================================
        $turnoverCheck = $this->walletService->checkTurnover($user);

        if ($turnoverCheck['has_active']) {
            $remaining = number_format($turnoverCheck['total_remaining'], 2);

            $details = $turnoverCheck['claims']->map(function ($claim) {
                $current  = number_format($claim->turnover_current, 2);
                $required = number_format($claim->turnover_required, 2);
                return "{$claim->type}: {$current}/{$required}";
            })->implode(', ');

            throw new \Exception(
                "ยังทำเทิร์นไม่ครบ ต้องเดิมพันอีก {$remaining} บาท ({$details})"
            );
        }

        $balanceBefore = $wallet->balance;

        $this->walletService->withdraw(
            $user,
            $amount,
            'ถอนเงิน #' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
            []
        );

        return Withdrawal::create([
            'user_id'        => $user->id,
            'reference_id'   => 'WD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(10)),
            'amount'         => $amount,
            'to_bank'        => $user->bank_code,
            'to_account'     => $user->bank_account,
            'to_name'        => $user->bank_name,
            'status'         => 'pending',
            'balance_before' => $balanceBefore,
            'balance_after'  => $balanceBefore - $amount,
        ]);
    }

    public function approve(Withdrawal $withdrawal, int $adminId): Withdrawal
    {
        if ($withdrawal->status !== 'pending') {
            throw new \Exception('รายการนี้ถูกดำเนินการแล้ว');
        }

        $withdrawal->update([
            'status'      => 'approved',
            'approved_by' => $adminId,
            'approved_at' => now(),
        ]);

                // 🆕 แจ้ง Sidebar อัพเดทจำนวน pending
        try {
            $pendingCount = Withdrawal::where('status', 'pending')->count();
            event(new \App\Events\AdminBadgeUpdated('withdrawal', $pendingCount));
        } catch (\Exception $e) {}

        return $withdrawal->fresh();
    }

    public function reject(Withdrawal $withdrawal, int $adminId, string $reason): Withdrawal
    {
        if ($withdrawal->status !== 'pending') {
            throw new \Exception('รายการนี้ถูกดำเนินการแล้ว');
        }

        $user = $withdrawal->user;
        $this->walletService->deposit(
            $user,
            $withdrawal->amount,
            'คืนเงินถอน #' . $withdrawal->reference_id,
            ['withdrawal_id' => $withdrawal->id],
            $adminId
        );

        $withdrawal->update([
            'status'        => 'rejected',
            'reject_reason' => $reason,
            'approved_by'   => $adminId,
            'approved_at'   => now(),
        ]);

                // 🆕 แจ้ง Sidebar อัพเดทจำนวน pending
        try {
            $pendingCount = Withdrawal::where('status', 'pending')->count();
            event(new \App\Events\AdminBadgeUpdated('withdrawal', $pendingCount));
        } catch (\Exception $e) {}

        return $withdrawal->fresh();
    }
}