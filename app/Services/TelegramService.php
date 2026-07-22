<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    public function send(string $message): bool
    {
        $token = Setting::getValue('telegram_bot_token');
        $chatId = Setting::getValue('telegram_chat_id');

        if (!$token || !$chatId) return false;

        try {
            $res = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'    => $chatId,
                'text'       => $message,
                'parse_mode' => 'HTML',
            ]);
            return $res->successful();
        } catch (\Exception $e) {
            Log::error("Telegram send failed: " . $e->getMessage());
            return false;
        }
    }

    public function sendTest(string $token, string $chatId): bool
    {
        try {
            $res = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'    => $chatId,
                'text'       => "✅ ทดสอบสำเร็จ!\nระบบแจ้งเตือน Telegram ใช้งานได้แล้ว",
                'parse_mode' => 'HTML',
            ]);
            return $res->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function notifyDeposit(string $username, float $amount): void
    {
        if (Setting::getValue('telegram_notify_deposit') !== 'true') return;
        $this->send("💰 <b>แจ้งฝากเงิน</b>\nUser: {$username}\nจำนวน: ฿" . number_format($amount, 2));
    }

    public function notifyWithdraw(string $username, float $amount): void
    {
        if (Setting::getValue('telegram_notify_withdraw') !== 'true') return;
        $this->send("💸 <b>แจ้งถอนเงิน</b>\nUser: {$username}\nจำนวน: ฿" . number_format($amount, 2));
    }

    public function notifyRegister(string $username, string $phone): void
    {
        if (Setting::getValue('telegram_notify_register') !== 'true') return;
        $this->send("👤 <b>สมาชิกใหม่</b>\nUser: {$username}\nเบอร์: {$phone}");
    }
}