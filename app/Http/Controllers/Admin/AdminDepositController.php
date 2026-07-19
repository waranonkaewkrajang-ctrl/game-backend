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
        $deposits = Deposit::with('user')
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
            'data'   => $deposits,
        ]);
    }

    public function approve(Request $request, Deposit $deposit): JsonResponse
    {
        try {
            $deposit = $this->depositService->approve($deposit, $request->user()->id);

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