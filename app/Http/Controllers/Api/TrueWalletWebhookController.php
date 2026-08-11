<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Setting;
use App\Models\TruewalletTransaction;
use App\Models\User;
use App\Services\DepositService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TrueWalletWebhookController extends Controller
{
    public function webhook(Request $request, DepositService $depositService)
    {
        $secret = env('TRUEWALLET_WEBHOOK_SECRET', '');

        // TrueWallet verify ping
        if ($request->has('test') || empty($request->getContent())) {
            return response()->json(['status' => 'ok']);
        }

        try {
            $raw = $request->getContent();
            $json = json_decode($raw, true);
            $token = $json['message'] ?? $raw;
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            $data   = (array) $decoded;
        } catch (\Exception $e) {
            $data = $request->all();
            if (empty($data)) {
                Log::warning('TrueWallet: decode failed', ['error' => $e->getMessage()]);
                return response()->json(['status' => 'error'], 400);
            }
        }

        Log::info('TrueWallet Webhook', $data);

        $amount        = floatval($data['amount'] ?? 0) / 100;
        $senderMobile  = $data['sender_mobile'] ?? '';
        $eventType     = $data['event_type'] ?? '';
        $receivedTime  = $data['received_time'] ?? now();
        $message       = $data['message'] ?? '';
        $txnId         = $data['transaction_id'] ?? $data['lat'] ?? Str::uuid()->toString();

        if ($amount <= 0) {
            return response()->json(['status' => 'skipped']);
        }

        $phone      = $this->normalizePhone($senderMobile);
        $twNumber   = Setting::where('key', 'truewallet_number')->value('value') ?? '';
        $refId      = 'TW-' . $txnId;

        // เช็ค duplicate
        if (TruewalletTransaction::where('transaction_id', $txnId)->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        // 🔒 SECURITY: บล็อก DIRECT_TOPUP / ไม่มีเบอร์ / เบอร์สั้นเกิน
        if ($eventType === 'DIRECT_TOPUP' || empty($phone) || strlen($phone) < 9) {
            Log::warning('TrueWallet BLOCKED Auto', [
                'reason' => 'DIRECT_TOPUP or invalid phone',
                'event_type' => $eventType,
                'phone' => $phone,
                'amount' => $amount,
                'channel' => $data['channel'] ?? '',
            ]);

            TruewalletTransaction::create([
                'transaction_id'  => $txnId,
                'event_type'      => $eventType,
                'amount'          => $amount,
                'sender_mobile'   => $phone,
                'receiver_mobile' => $twNumber,
                'message'         => $message,
                'status'          => 'unmatched',
                'user_id'         => null,
                'raw_data'        => json_encode($data),
                'received_at'     => $receivedTime,
            ]);

            return response()->json([
                'status' => 'requires_manual',
                'message' => 'DIRECT_TOPUP - แอดมินต้องตรวจสอบเอง',
            ]);
        }

        // 🔒 SECURITY: ต้อง match 3 เงื่อนไข
        // 1. phone มีความยาว 10 หลัก
        // 2. bank_code = TRUEWALLET
        // 3. bank_account = เบอร์ที่โอนมา (เป๊ะ)
        
        if (strlen($phone) !== 10) {
            Log::warning('TrueWallet BLOCKED: phone ไม่ครบ 10 หลัก', [
                'phone' => $phone,
                'amount' => $amount,
            ]);

            TruewalletTransaction::create([
                'transaction_id'  => $txnId,
                'event_type'      => $eventType,
                'amount'          => $amount,
                'sender_mobile'   => $phone,
                'receiver_mobile' => $twNumber,
                'message'         => $message,
                'status'          => 'unmatched',
                'user_id'         => null,
                'raw_data'        => json_encode($data),
                'received_at'     => $receivedTime,
            ]);

            return response()->json(['status' => 'invalid_phone']);
        }

        // 🔒 หา user ที่มี bank_code = TRUEWALLET + bank_account = เบอร์เป๊ะ
        $matches = User::where('bank_code', 'TRUEWALLET')
            ->where('bank_account', $phone)
            ->get();

        if ($matches->count() === 0) {
            Log::warning('TrueWallet BLOCKED: ไม่พบ user สมัคร TrueWallet เบอร์นี้', [
                'phone' => $phone,
                'amount' => $amount,
            ]);

            TruewalletTransaction::create([
                'transaction_id'  => $txnId,
                'event_type'      => $eventType,
                'amount'          => $amount,
                'sender_mobile'   => $phone,
                'receiver_mobile' => $twNumber,
                'message'         => $message,
                'status'          => 'unmatched',
                'user_id'         => null,
                'raw_data'        => json_encode($data),
                'received_at'     => $receivedTime,
            ]);

            return response()->json(['status' => 'user_not_found']);
        }

        if ($matches->count() > 1) {
            Log::error('TrueWallet BLOCKED: พบ user หลายคนใช้เบอร์เดียวกัน!', [
                'phone' => $phone,
                'amount' => $amount,
                'usernames' => $matches->pluck('username')->toArray(),
            ]);

            TruewalletTransaction::create([
                'transaction_id'  => $txnId,
                'event_type'      => $eventType,
                'amount'          => $amount,
                'sender_mobile'   => $phone,
                'receiver_mobile' => $twNumber,
                'message'         => $message,
                'status'          => 'unmatched',
                'user_id'         => null,
                'raw_data'        => json_encode($data),
                'received_at'     => $receivedTime,
            ]);

            return response()->json(['status' => 'multiple_users']);
        }

        $user = $matches->first();

        // ✅ เจอ user แล้ว → สร้าง TruewalletTransaction record
        $twTx = TruewalletTransaction::create([
            'transaction_id'  => $txnId,
            'event_type'      => $eventType,
            'amount'          => $amount,
            'sender_mobile'   => $phone,
            'receiver_mobile' => $twNumber,
            'message'         => $message,
            'status'          => 'matched',
            'user_id'         => $user->id,
            'raw_data'        => json_encode($data),
            'received_at'     => $receivedTime,
        ]);

        // เช็ค deposit ซ้ำ
        if (Deposit::where('reference_id', $refId)->exists()) {
            $twTx->update(['status' => 'duplicate']);
            return response()->json(['status' => 'duplicate']);
        }

        // สร้าง deposit + auto approve
        $deposit = Deposit::create([
            'user_id'      => $user->id,
            'reference_id' => $refId,
            'amount'       => $amount,
            'channel'      => 'truewallet',
            'from_bank'    => 'truewallet',
            'from_account' => $phone,
            'to_bank'      => 'truewallet',
            'to_account'   => $twNumber,
            'status'       => 'pending',
        ]);

        try {
            $depositService->approve($deposit, 1);
            $twTx->update(['deposit_id' => $deposit->id]);

            Log::info('TrueWallet Auto OK', [
                'user'   => $user->username,
                'phone'  => $phone,
                'amount' => $amount,
            ]);

            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            Log::error('TrueWallet approve failed', ['error' => $e->getMessage()]);
            return response()->json(['status' => 'pending']);
        }
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($phone, '66')) {
            $phone = '0' . substr($phone, 2);
        }
        return $phone;
    }
}