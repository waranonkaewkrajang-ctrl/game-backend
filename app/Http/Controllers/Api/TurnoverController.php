<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TurnoverController extends Controller
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    /**
     * GET /api/turnover/status
     * ดูสถานะเทิร์นโอเวอร์ของ user ที่ login อยู่
     */
    public function status(Request $request): JsonResponse
    {
        $user  = $request->user();
        $check = $this->walletService->checkTurnover($user);

        $claims = $check['claims']->map(fn ($c) => [
            'id'                  => $c->id,
            'type'                => $c->type,
            'bonus_amount'        => (float) $c->bonus_amount,
            'turnover_multiplier' => (float) $c->turnover_multiplier,
            'turnover_required'   => (float) $c->turnover_required,
            'turnover_current'    => (float) $c->turnover_current,
            'remaining'           => (float) $c->remaining,
            'progress_percent'    => $c->progress_percent,
            'expired_at'          => $c->expired_at?->toIso8601String(),
            'created_at'          => $c->created_at->toIso8601String(),
        ]);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'has_active_turnover' => $check['has_active'],
                'total_remaining'     => $check['total_remaining'],
                'can_withdraw'        => !$check['has_active'],
                'claims'              => $claims->values(),
            ],
        ]);
    }
}