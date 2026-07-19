<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepositController extends Controller
{
    public function __construct(
        private DepositService $depositService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount'       => 'required|numeric|min:1',
            'channel'      => 'required|string|in:bank_transfer,truewallet,promptpay',
            'from_bank'    => 'nullable|string|max:10',
            'from_account' => 'nullable|string|max:20',
            'to_bank'      => 'nullable|string|max:10',
            'to_account'   => 'nullable|string|max:20',
            'slip_url'     => 'nullable|url',
            'promotion_id' => 'nullable|exists:promotions,id',
        ]);

        try {
            $deposit = $this->depositService->createRequest($request->user(), $data);

            return response()->json([
                'status'  => 'success',
                'message' => 'สร้างคำขอฝากเงินสำเร็จ',
                'data'    => $deposit,
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
        $deposits = $request->user()
            ->deposits()
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data'   => $deposits,
        ]);
    }

    public function show(Request $request, int $depositId): JsonResponse
    {
        $deposit = $request->user()
            ->deposits()
            ->findOrFail($depositId);

        return response()->json([
            'status' => 'success',
            'data'   => $deposit,
        ]);
    }
}