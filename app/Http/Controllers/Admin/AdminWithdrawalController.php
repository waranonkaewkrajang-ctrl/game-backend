<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWithdrawalController extends Controller
{
    public function __construct(
        private WithdrawalService $withdrawalService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $withdrawals = Withdrawal::with('user')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
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
            'data'   => $withdrawals,
        ]);
    }

    public function approve(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        try {
            $withdrawal = $this->withdrawalService->approve($withdrawal, $request->user()->id);

            app(\App\Services\TelegramService::class)->notifyWithdraw($withdrawal->user->username, $withdrawal->amount);

            return response()->json([
                'status'  => 'success',
                'message' => 'อนุมัติถอนเงินสำเร็จ',
                'data'    => $withdrawal->load('user'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function reject(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        try {
            $withdrawal = $this->withdrawalService->reject($withdrawal, $request->user()->id, $data['reason']);

            return response()->json([
                'status'  => 'success',
                'message' => 'ปฏิเสธการถอนเงินสำเร็จ',
                'data'    => $withdrawal->load('user'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}