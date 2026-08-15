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

    public function fetchChatIds(string $token): array
    {
        try {
            $res = Http::timeout(15)->get("https://api.telegram.org/bot{$token}/getUpdates");
            $data = $res->json();

            if (!($data['ok'] ?? false)) {
                return ['status' => 'error', 'message' => $data['description'] ?? 'Bot Token ไม่ถูกต้อง'];
            }

            $chats = [];
            foreach ($data['result'] ?? [] as $update) {
                $msg = $update['message'] ?? $update['channel_post'] ?? $update['my_chat_member'] ?? null;
                if (!$msg) continue;
                $chat = $msg['chat'] ?? null;
                if (!$chat || empty($chat['id'])) continue;

                $title = $chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''));
                if ($title === '') $title = $chat['username'] ?? 'ไม่ทราบชื่อ';

                $chats[(string) $chat['id']] = [
                    'id'    => (string) $chat['id'],
                    'type'  => $chat['type'] ?? '',
                    'title' => $title,
                ];
            }

            if (empty($chats)) {
                return ['status' => 'empty', 'message' => 'ไม่พบแชท — เพิ่ม Bot เข้ากลุ่ม แล้วพิมพ์ข้อความในกลุ่มก่อน'];
            }

            return ['status' => 'success', 'data' => array_values($chats)];
        } catch (\Throwable $e) {
            Log::error('Telegram fetchChatIds failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
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