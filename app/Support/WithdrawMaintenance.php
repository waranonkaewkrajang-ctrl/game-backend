<?php

namespace App\Support;

use App\Models\Setting;

/**
 * "ปิดปรับปรุงถอนเงิน" — switched on/off in the admin (ตั้งค่าการเงิน → ปิดปรับปรุงถอนเงิน).
 * Settings (table settings): withdraw_maintenance ("true"/"false"), withdraw_maintenance_title, withdraw_maintenance_message.
 * While on, the customer site shows the admin's text as a popup when the customer presses withdraw, and the
 * server refuses new withdrawal requests (so an old page / direct API call cannot get around it).
 * Read fresh from the database every time (no cache) so switching it off takes effect immediately.
 */
class WithdrawMaintenance
{
    public const DEFAULT_TITLE = 'ปิดปรับปรุงระบบถอนเงินชั่วคราว';
    public const DEFAULT_MESSAGE = 'ขออภัยในความไม่สะดวก ระบบถอนเงินปิดปรับปรุงชั่วคราว กรุณาลองใหม่อีกครั้งภายหลัง';

    /** @return array{enabled: bool, title: string, message: string} */
    public static function state(): array
    {
        $s = Setting::whereIn('key', ['withdraw_maintenance', 'withdraw_maintenance_title', 'withdraw_maintenance_message'])
            ->pluck('value', 'key');
        $title = trim((string) ($s['withdraw_maintenance_title'] ?? ''));
        $message = trim((string) ($s['withdraw_maintenance_message'] ?? ''));

        return [
            'enabled' => filter_var($s['withdraw_maintenance'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
            'title' => mb_substr($title !== '' ? $title : self::DEFAULT_TITLE, 0, 100),
            'message' => mb_substr($message !== '' ? $message : self::DEFAULT_MESSAGE, 0, 1000),
        ];
    }
}
