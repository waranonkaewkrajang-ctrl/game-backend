<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UnmatchedDeposit;
use App\Models\User;
use App\Services\WalletService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUnmatchedDepositController extends Controller
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    // ดึงรายการทั้งหมด
    public function index(Request $request): JsonResponse
    {
        $items = UnmatchedDeposit::with('user')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json(['status' => 'success', 'data' => $items]);
    }

    // สร้างรายการใหม่ (จาก monitor.py)
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank'         => 'nullable|string|max:50',
            'amount'       => 'required|numeric|min:0.01',
            'from_account' => 'nullable|string|max:255',
            'tx_time'      => 'nullable|string|max:100',
        ]);

        $$item = UnmatchedDeposit::create($data);
        // ยิง event อัพเดท badge ยอดค้าง
        $pendingCount = UnmatchedDeposit::where('status', 'pending')->count();
        event(new \App\Events\AdminBadgeUpdated('unmatched', $pendingCount));

        // แจ้ง Telegram
        try {
            app(TelegramService::class)->send(
                "⚠️ <b>เงินเข้าไม่มีคนแจ้งฝาก</b>\n" .
                "💵 จำนวน: ฿" . number_format($item->amount, 2) . "\n" .
                "🏦 ธนาคาร: {$item->bank}\n" .
                "👤 จาก: {$item->from_account}\n" .
                "📅 เวลา: {$item->tx_time}"
            );
        } catch (\Exception $e) {}

        return response()->json(['status' => 'success', 'data' => $item], 201);
    }

    // อนุมัติ — ใส่ username หรือเบอร์ลูกค้า แล้วเติมเงินเข้า
    public function approve(Request $request, UnmatchedDeposit $unmatchedDeposit): JsonResponse
    {
        if ($unmatchedDeposit->status !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'รายการนี้ดำเนินการแล้ว'], 400);
        }

        $data = $request->validate([
            'username_or_phone' => 'required|string',
        ]);

        // หา user จาก username หรือ เบอร์โทร
        $user = User::where('username', $data['username_or_phone'])
            ->orWhere('phone', $data['username_or_phone'])
            ->first();

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'ไม่พบสมาชิก "' . $data['username_or_phone'] . '"'], 404);
        }

        // สร้างรายการฝากในระบบ
        $deposit = \App\Models\Deposit::create([
            'user_id'      => $user->id,
            'reference_id' => 'UMD-' . now()->format('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(8)),
            'amount'       => $unmatchedDeposit->amount,
            'channel'      => 'bank_transfer',
            'from_bank'    => $unmatchedDeposit->bank,
            'from_account' => $unmatchedDeposit->from_account,
            'status'       => 'approved',
            'approved_by'  => $request->user()->id,
            'approved_at'  => now(),
        ]);

        // เติมเงินเข้ากระเป๋า
        $this->walletService->deposit(
            $user,
            (float) $unmatchedDeposit->amount,
            'เติมเงินจากยอดค้าง #' . $unmatchedDeposit->id,
            ['unmatched_deposit_id' => $unmatchedDeposit->id, 'deposit_id' => $deposit->id],
            $request->user()->id
        );

        $unmatchedDeposit->update([
            'status'          => 'approved',
            'matched_user_id' => $user->id,
            'approved_by'     => $request->user()->id,
            'approved_at'     => now(),
        ]);

        // ← ★ เพิ่มตรงนี้ ระหว่าง update กับ แจ้ง Telegram ★

        // บันทึกรายการเดินบัญชี
        try {
            \App\Models\BankStatement::create([
                'deposit_id'       => $deposit->id,
                'user_id'          => $user->id,
                'amount'           => $unmatchedDeposit->amount,
                'bank_code'        => null,
                'bank_account'     => null,
                'bank_name'        => null,
                'from_name'        => null,
                'from_account'     => $unmatchedDeposit->from_account,
                'from_bank_code'   => $unmatchedDeposit->bank,
                'reference_id'     => $deposit->reference_id,
                'approved_method'  => 'manual',
                'approved_by'      => $request->user()->id,
                'transaction_time' => now(),
            ]);
        } catch (\Exception $e) {
            \Log::error("BankStatement log failed for unmatched #{$unmatchedDeposit->id}: {$e->getMessage()}");
        }

        // แจ้ง Telegram   ← บรรทัดเดิมที่มีอยู่แล้ว
        try {
            app(TelegramService::class)->send(
                "✅ <b>อนุมัติยอดค้าง</b>\n" .
                "💵 จำนวน: ฿" . number_format($unmatchedDeposit->amount, 2) . "\n" .
                "👤 เติมให้: {$user->username}\n" .
                "🏦 ธนาคาร: {$unmatchedDeposit->bank}"
            );
        } catch (\Exception $e) {}

        return response()->json([
            'status'  => 'success',
            'message' => "เติมเงิน ฿" . number_format($unmatchedDeposit->amount, 2) . " ให้ {$user->username} สำเร็จ",
            'data'    => $unmatchedDeposit->fresh()->load('user'),
        ]);
    }

    // ลบ/ปฏิเสธรายการ
    public function reject(Request $request, UnmatchedDeposit $unmatchedDeposit): JsonResponse
    {
        if ($unmatchedDeposit->status !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'รายการนี้ดำเนินการแล้ว'], 400);
        }

        $unmatchedDeposit->update([
            'status'      => 'rejected',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'note'        => $request->input('note', 'ลบโดย Admin'),
        ]);

        return response()->json(['status' => 'success', 'message' => 'ลบรายการสำเร็จ']);
    }
}