<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function balance(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet;

        return response()->json([
            'status' => 'success',
            'data'   => [
                'balance'        => (float) $wallet->balance,
                'bonus_balance'  => (float) $wallet->bonus_balance,
                'total_deposit'  => (float) $wallet->total_deposit,
                'total_withdraw' => (float) $wallet->total_withdraw,
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $transactions = $request->user()
            ->transactions()
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data'   => $transactions,
        ]);
    }
}