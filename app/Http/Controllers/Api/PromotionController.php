<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Services\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    public function __construct(
        private PromotionService $promotionService,
    ) {}

    public function index(): JsonResponse
    {
        $promotions = Promotion::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('start_at')->orWhere('start_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_at')->orWhere('end_at', '>=', now());
            })
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $promotions,
        ]);
    }

    public function show(Promotion $promotion): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => $promotion,
        ]);
    }

    public function claim(Request $request, Promotion $promotion): JsonResponse
    {
        $user = $request->user();

        $check = $this->promotionService->canClaim($user, $promotion);

        if (!$check['can_claim']) {
            return response()->json([
                'status'  => 'error',
                'message' => $check['reason'],
            ], 400);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'สามารถใช้โปรโมชันนี้ได้ กรุณาฝากเงินพร้อมเลือกโปร',
            'data'    => $promotion,
        ]);
    }
}