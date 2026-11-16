<?php

namespace App\Domain\Events;

use App\Models\Deposit;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;

/**
 * สัญญา event (schema_version 1) — ส่งเฉพาะข้อมูลที่ CRM ต้องใช้
 * ห้ามใส่: รหัสผ่าน, token, เลขบัญชีธนาคาร, ชื่อบัญชี, ยอดคงเหลือ wallet
 */
final class DomainEvents
{
    public const MEMBER_REGISTERED        = 'member.registered';
    public const MEMBER_ACTIVITY_UPDATED  = 'member.activity_updated';
    public const MEMBER_STATUS_CHANGED    = 'member.status_changed';
    public const MEMBER_CONSENT_UPDATED   = 'member.marketing_consent_updated';
    public const MEMBER_OPTED_OUT         = 'member.opted_out';
    public const DEPOSIT_APPROVED         = 'deposit.approved';
    public const WITHDRAWAL_APPROVED      = 'withdrawal.approved';
    public const WITHDRAWAL_REJECTED      = 'withdrawal.rejected';

    public static function iso($v): ?string
    {
        return $v ? Carbon::parse($v)->toIso8601String() : null;
    }

    /** snapshot ของสมาชิกที่ CRM ใช้ (ใช้ทั้ง member.registered และการนำเข้าครั้งแรก) */
    public static function memberSnapshot(User $u): array
    {
        return [
            'member_id'        => (int) $u->id,
            'username'         => (string) $u->username,
            'phone'            => $u->phone ? (string) $u->phone : null,
            'status'           => (string) ($u->status ?? 'active'),
            'registered_at'    => self::iso($u->created_at),
            'last_login_at'    => self::iso($u->last_login_at ?? null),
            'last_activity_at' => self::iso($u->getAttribute('last_activity_at')),
            'consent'          => self::consent($u),
        ];
    }

    public static function consent(User $u): array
    {
        return [
            'sms_consent_at'  => self::iso($u->getAttribute('marketing_sms_consent_at')),
            'line_consent_at' => self::iso($u->getAttribute('marketing_line_consent_at')),
            'opted_out_at'    => self::iso($u->getAttribute('marketing_opted_out_at')),
        ];
    }

    public static function deposit(Deposit $d): array
    {
        return [
            'deposit_id'  => (int) $d->id,
            'member_id'   => (int) $d->user_id,
            'amount'      => number_format((float) $d->amount, 2, '.', ''),
            'approved_at' => self::iso($d->approved_at ?? now()),
        ];
    }

    public static function withdrawal(Withdrawal $w): array
    {
        return [
            'withdrawal_id' => (int) $w->id,
            'member_id'     => (int) $w->user_id,
            'amount'        => number_format((float) $w->amount, 2, '.', ''),
            'decided_at'    => self::iso($w->approved_at ?? now()),
        ];
    }
}
