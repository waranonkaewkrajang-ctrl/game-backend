<?php

namespace App\Observers;

use App\Domain\Events\DomainEvents;
use App\Domain\Events\OutboxRecorder;
use App\Models\User;

/**
 * สมาชิก → outbox (ทำงานใน transaction เดียวกับการบันทึก User เสมอ ไม่มี network call)
 * last_activity_at ไม่ผ่านที่นี่ (middleware เขียนด้วย query builder + บันทึก event เอง)
 */
class MemberEventsObserver
{
    public function __construct(private OutboxRecorder $outbox) {}

    public function created(User $user): void
    {
        $this->outbox->record(DomainEvents::MEMBER_REGISTERED, 'member', $user->id, DomainEvents::memberSnapshot($user), $user->created_at);
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('status')) {
            $this->outbox->record(DomainEvents::MEMBER_STATUS_CHANGED, 'member', $user->id, [
                'member_id'       => (int) $user->id,
                'status'          => (string) $user->status,
                'previous_status' => $user->getOriginal('status'),
                'changed_at'      => now()->toIso8601String(),
            ]);
        }
        if ($user->wasChanged(['marketing_sms_consent_at', 'marketing_line_consent_at'])) {
            $this->outbox->record(DomainEvents::MEMBER_CONSENT_UPDATED, 'member', $user->id, [
                'member_id' => (int) $user->id,
            ] + DomainEvents::consent($user));
        }
        if ($user->wasChanged('marketing_opted_out_at') && $user->getAttribute('marketing_opted_out_at')) {
            $this->outbox->record(DomainEvents::MEMBER_OPTED_OUT, 'member', $user->id, [
                'member_id'    => (int) $user->id,
                'channel'      => 'all',
                'opted_out_at' => DomainEvents::iso($user->getAttribute('marketing_opted_out_at')),
            ]);
        }
    }
}
