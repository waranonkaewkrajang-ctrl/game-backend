<?php

namespace App\Services;

use App\Models\Deposit;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;

class DepositService
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    public function createRequest(User $user, array $data): Deposit
    {
        $amount = (float) $data['amount'];

        // ตรวจสอบขั้นต่ำ-สูงสุดจาก settings
        $minDeposit = (float) Setting::getValue('min_deposit', 100);
        $maxDeposit = (float) Setting::getValue('max_deposit', 200000);

        if ($amount < $minDeposit) {
            throw new \Exception("ฝากขั้นต่ำ {$minDeposit} บาท");
        }

        if ($amount > $maxDeposit) {
            throw new \Exception("ฝากสูงสุด {$maxDeposit} บาท");
        }

        // ตรวจสอบว่าช่องทางเปิดอยู่ไหม
        $enabledChannels = json_decode(Setting::getValue('deposit_channels', '["bank_transfer","promptpay","truewallet"]'), true) ?: ['bank_transfer', 'promptpay', 'truewallet'];

        if (!in_array($data['channel'], $enabledChannels)) {
            throw new \Exception('ช่องทางนี้ปิดให้บริการชั่วคราว');
        }

        return Deposit::create([
            'user_id'      => $user->id,
            'reference_id' => 'DEP-' . now()->format('Ymd') . '-' . strtoupper(Str::random(10)),
            'amount'       => $amount,
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

        $deposit = $deposit->fresh();

        // 🆕 บันทึก Bank Statement + broadcast (กัน error ไม่ให้กระทบ approve)
        try {
            // ดึงข้อมูลบัญชีลูกค้า (ผู้โอน) จาก User
            $user = $deposit->user;
            $fromName = $user?->bank_name ?? $deposit->from_account ?? null;
            $fromAccount = $user?->bank_account ?? null;
            $fromBankCode = $user?->bank_code ?? null;

            // ดึงข้อมูลบัญชีของเรา (ผู้รับ) จาก finance settings
            $bankName = null;
            try {
                $settingRaw = \App\Models\Setting::where('key', 'deposit_banks')->value('value');
                $banks = $settingRaw ? json_decode($settingRaw, true) : [];
                $matched = collect($banks)->firstWhere('bank_account', $deposit->to_account)
                        ?? collect($banks)->firstWhere('bank_code', $deposit->to_bank ?? 'SCB');
                $bankName = $matched['bank_name'] ?? null;
            } catch (\Exception $e) {
                // ignore — ไม่มี setting ก็ได้
            }

            $statement = \App\Models\BankStatement::create([
                'deposit_id'       => $deposit->id,
                'user_id'          => $deposit->user_id,
                'amount'           => $deposit->amount,
                'bank_code'        => $deposit->to_bank ?? 'SCB',
                'bank_account'     => $deposit->to_account,
                'bank_name'        => $bankName,
                'from_name'        => $fromName,
                'from_account'     => $fromAccount,
                'from_bank_code'   => $fromBankCode,
                'reference_id'     => $deposit->reference_id,
                'approved_method'  => $deposit->approved_method ?? 'manual',
                'approved_by'      => $adminId,
                'transaction_time' => $deposit->approved_at,
            ]);
            broadcast(new \App\Events\BankStatementReceived($statement));
        } catch (\Exception $e) {
            \Log::error("BankStatement log failed for deposit #{$deposit->id}: {$e->getMessage()}");
        }

        return $deposit;
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