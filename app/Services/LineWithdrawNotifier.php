<?php

namespace App\Services;

use App\Models\Withdrawal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 🆕 แจ้งสถานะถอนเป็นการ์ด LINE ให้ลูกค้า (เพิ่มใหม่ — ไม่แตะ logic การเงินเดิม)
 *
 * เรียก API ของ admin-api: POST {WITHDRAW_NOTIFY_URL}  (เช่น https://admin-api.../withdraw-notify/events)
 * - ทำงาน "หลังส่ง response ให้ลูกค้า/แอดมินแล้ว" (app()->terminating) → ไม่ทำให้การถอน/อนุมัติช้าลง
 * - ไม่ throw ออกไปเด็ดขาด: LINE / admin-api ล่ม ก็ไม่กระทบรายการถอน
 * - ไม่ได้ตั้ง WITHDRAW_NOTIFY_URL หรือ WITHDRAW_NOTIFY_SECRET = ปิด (ไม่ส่งอะไร)
 */
class LineWithdrawNotifier
{
    /** สถานะที่ admin-api รู้จัก: pending | approved | refunded */
    public const PENDING  = 'pending';
    public const APPROVED = 'approved';
    public const REFUNDED = 'refunded';

    public function notify(?Withdrawal $withdrawal, string $status): void
    {
        try {
            if (!$withdrawal || !$this->enabled()) {
                return;
            }
            $payload = $this->payload($withdrawal, $status);
            if ($payload === null) {
                return;
            }
            app()->terminating(fn () => $this->send($payload));
        } catch (\Throwable $e) {
            Log::warning('[LineWithdrawNotifier] skipped: ' . $e->getMessage());
        }
    }

    public function enabled(): bool
    {
        return (bool) config('services.withdraw_notify.url') && strlen((string) config('services.withdraw_notify.secret')) >= 16;
    }

    /** ข้อมูลที่ส่งไป admin-api (ไม่มีชื่อ/ยอดคงเหลือ/รหัสผ่านใดๆ) */
    public function payload(Withdrawal $w, string $status): ?array
    {
        if (!in_array($status, [self::PENDING, self::APPROVED, self::REFUNDED], true)) {
            return null;
        }
        $phone = optional($w->user)->phone;
        if (!$phone) {
            return null;
        }
        return [
            'withdraw_id'    => (string) ($w->reference_id ?: $w->id),
            'phone'          => (string) $phone,
            'status'         => $status,
            'amount'         => (float) $w->amount,
            'bank_code'      => (string) ($w->to_bank ?? ''),
            'account_number' => (string) ($w->to_account ?? ''),
            'requested_at'   => optional($w->created_at)->toIso8601String(),
        ];
    }

    public function send(array $payload): bool
    {
        try {
            $res = Http::withHeaders(['x-withdraw-notify-key' => (string) config('services.withdraw_notify.secret')])
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout(4)
                ->post((string) config('services.withdraw_notify.url'), $payload);
            if (!$res->successful()) {
                Log::warning("[LineWithdrawNotifier] {$payload['withdraw_id']} {$payload['status']} → HTTP {$res->status()}");
            }
            return $res->successful();
        } catch (\Throwable $e) {
            Log::warning("[LineWithdrawNotifier] {$payload['withdraw_id']} {$payload['status']} failed: " . $e->getMessage());
            return false;
        }
    }
}
