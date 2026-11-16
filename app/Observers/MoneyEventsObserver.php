<?php

namespace App\Observers;

use App\Domain\Events\DomainEvents;
use App\Domain\Events\OutboxRecorder;
use App\Models\Deposit;
use App\Models\Withdrawal;

/**
 * ฝากอนุมัติ / ถอนอนุมัติ / ถอนถูกปฏิเสธ → outbox
 * ไม่แก้ logic การเงินเดิม: แค่ "ฟัง" การเปลี่ยนสถานะที่ flow เดิมทำอยู่แล้วภายใน DB::transaction ของมันเอง
 * (DepositService::approve, AdminUnmatchedDepositController::approve, WithdrawalService::approve/reject)
 */
class MoneyEventsObserver
{
    public function __construct(private OutboxRecorder $outbox) {}

    public function created($model): void
    {
        // ยอดค้างที่แอดมินจับคู่: สร้าง Deposit สถานะ approved ทันที
        if ($model instanceof Deposit && $model->status === 'approved') {
            $this->outbox->record(DomainEvents::DEPOSIT_APPROVED, 'member', $model->user_id, DomainEvents::deposit($model));
        }
    }

    public function updated($model): void
    {
        if (!$model->wasChanged('status')) {
            return;
        }
        if ($model instanceof Deposit && $model->status === 'approved') {
            $this->outbox->record(DomainEvents::DEPOSIT_APPROVED, 'member', $model->user_id, DomainEvents::deposit($model));
        } elseif ($model instanceof Withdrawal && $model->status === 'approved') {
            $this->outbox->record(DomainEvents::WITHDRAWAL_APPROVED, 'member', $model->user_id, DomainEvents::withdrawal($model));
        } elseif ($model instanceof Withdrawal && $model->status === 'rejected') {
            $this->outbox->record(DomainEvents::WITHDRAWAL_REJECTED, 'member', $model->user_id, DomainEvents::withdrawal($model));
        }
    }
}
