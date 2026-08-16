<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Services\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDepositController extends Controller
{
    public function __construct(
        private DepositService $depositService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $deposits = Deposit::with(['user', 'admin'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->date_from, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->date_to, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($request->search, function ($q, $search) {
                $q->whereHas('user', function ($q) use ($search) {
                    $q->where('username', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data'   => $deposits,
        ]);
    }

    public function approve(Request $request, Deposit $deposit): JsonResponse
    {
        try {
            // บล็อก B — set approved_method ก่อน
$deposit->update([
   'approved_method' => $request->input('approved_method', 'manual'),
]);

// บล็อก A — แล้วค่อย approve (ตอนสร้าง bank_statement จะเห็น approved_method แล้ว)
$deposit = $this->depositService->approve($deposit, $request->user()->id);

            // 🆕 Broadcast event ไปหา user ที่ฝาก → frontend จะเด้ง popup
            broadcast(new \App\Events\DepositApproved($deposit->fresh()));

            app(\App\Services\TelegramService::class)->notifyDeposit($deposit->user->username, $deposit->amount);

            return response()->json([
                'status'  => 'success',
                'message' => 'อนุมัติฝากเงินสำเร็จ',
                'data'    => $deposit->load('user'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function reject(Request $request, Deposit $deposit): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        try {
            $deposit = $this->depositService->reject($deposit, $request->user()->id, $data['reason']);

            return response()->json([
                'status'  => 'success',
                'message' => 'ปฏิเสธการฝากเงินสำเร็จ',
                'data'    => $deposit->load('user'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}