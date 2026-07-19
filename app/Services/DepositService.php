<?php

namespace App\Services;

use App\Models\Deposit;
use App\Models\User;
use Illuminate\Support\Str;

class DepositService
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    public function createRequest(User $user, array $data): Deposit
    {
        return Deposit::create([
            'user_id'      => $user->id,
            'reference_id' => 'DEP-' . now()->format('Ymd') . '-' . strtoupper(Str::random(10)),
            'amount'       => $data['amount'],
            'channel'      => $data['channel'],
            'from_bank'    => $data['from_bank'] ?? null,
            'from_account' => $data['from_account'] ?? null,
            'to_bank'      => $data['to_bank'] ?? null,
            'to_account'   => $data['to_account'] ?? null,
            'slip_url'     => $data['slip_url'] ?? null,
            'promotion_id' => $data['promotion_id'] ?? null,
            'status'       => 'pending',
        ]);
    }

    public function approve(Deposit $deposit, int $adminId): Deposit
    {
        if ($deposit->status !== 'pending') {
            throw new \Exception('รายการนี้ถูกดำเนินการแล้ว');
        }

        $user = $deposit->user;

        $this->walletService->deposit(
            $user,
            $deposit->amount,
            'ฝากเงิน #' . $deposit->reference_id,
            ['deposit_id' => $deposit->id],
            $adminId
        );

        $deposit->update([
            'status'      => 'approved',
            'approved_by' => $adminId,
            'approved_at' => now(),
        ]);

        return $deposit->fresh();
    }

    public function reject(Deposit $deposit, int $adminId, string $reason): Deposit
    {
        if ($deposit->status !== 'pending') {
            throw new \Exception('รายการนี้ถูกดำเนินการแล้ว');
        }

        $deposit->update([
            'status'        => 'rejected',
            'reject_reason' => $reason,
            'approved_by'   => $adminId,
            'approved_at'   => now(),
        ]);

        return $deposit->fresh();
    }
}