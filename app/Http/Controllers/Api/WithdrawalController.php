<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function __construct(
        private WithdrawalService $withdrawalService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        try {
            $withdrawal = $this->withdrawalService->createRequest(
                $request->user(),
                $data['amount']
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'สร้างคำขอถอนเงินสำเร็จ',
                'data'    => $withdrawal,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $withdrawals = $request->user()
            ->withdrawals()
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data'   => $withdrawals,
        ]);
    }

    public function show(Request $request, int $withdrawalId): JsonResponse
    {
        $withdrawal = $request->user()
            ->withdrawals()
            ->findOrFail($withdrawalId);

        return response()->json([
            'status' => 'success',
            'data'   => $withdrawal,
        ]);
    }
}