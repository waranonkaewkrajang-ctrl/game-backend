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

        // Decode JWT
        try {
            $token  = $request->getContent();
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

        $amount        = floatval($data['amount'] ?? 0);
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

        // หา user จากเบอร์โทร
        $user = User::where('phone', $phone)
            ->orWhere('phone', 'LIKE', '%' . substr($phone, -9))
            ->first();

        // บันทึกทุกรายการลง truewallet_transactions (เดินบัญชี)
        $twTx = TruewalletTransaction::create([
            'transaction_id'  => $txnId,
            'event_type'      => $eventType,
            'amount'          => $amount,
            'sender_mobile'   => $phone,
            'receiver_mobile' => $twNumber,
            'message'         => $message,
            'status'          => $user ? 'matched' : 'unmatched',
            'user_id'         => $user?->id,
            'raw_data'        => json_encode($data),
            'received_at'     => $receivedTime,
        ]);

        // ไม่เจอ user → บันทึกไว้แต่ไม่ฝาก
        if (!$user) {
            Log::warning('TrueWallet: ไม่พบ user เบอร์ ' . $phone, ['amount' => $amount]);
            return response()->json(['status' => 'user_not_found']);
        }

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