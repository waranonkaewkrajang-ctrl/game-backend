<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $promotions = Promotion::when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data'   => $promotions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'               => 'required|string|max:255',
            'description'         => 'nullable|string',
            'image_url'           => 'nullable|url',
            'type'                => 'required|in:welcome_bonus,deposit_bonus,cashback,free_credit,referral_bonus',
            'min_deposit'         => 'nullable|numeric|min:0',
            'max_bonus'           => 'nullable|numeric|min:0',
            'bonus_percent'       => 'nullable|numeric|min:0|max:999',
            'turnover_multiplier' => 'nullable|numeric|min:0',
            'max_withdraw'        => 'nullable|numeric|min:0',
            'is_active'           => 'nullable|boolean',
            'max_claims'          => 'nullable|integer|min:1',
            'claims_per_user'     => 'nullable|integer|min:1',
            'start_at'            => 'nullable|date',
            'end_at'              => 'nullable|date|after:start_at',
        ]);

        $promotion = Promotion::create($data);

        return response()->json([
            'status'  => 'success',
            'message' => 'สร้างโปรโมชันสำเร็จ',
            'data'    => $promotion,
        ], 201);
    }

    public function show(Promotion $promotion): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => $promotion->load('claims'),
        ]);
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $data = $request->validate([
            'title'               => 'nullable|string|max:255',
            'description'         => 'nullable|string',
            'image_url'           => 'nullable|url',
            'type'                => 'nullable|in:welcome_bonus,deposit_bonus,cashback,free_credit,referral_bonus',
            'min_deposit'         => 'nullable|numeric|min:0',
            'max_bonus'           => 'nullable|numeric|min:0',
            'bonus_percent'       => 'nullable|numeric|min:0|max:999',
            'turnover_multiplier' => 'nullable|numeric|min:0',
            'max_withdraw'        => 'nullable|numeric|min:0',
            'is_active'           => 'nullable|boolean',
            'max_claims'          => 'nullable|integer|min:1',
            'claims_per_user'     => 'nullable|integer|min:1',
            'start_at'            => 'nullable|date',
            'end_at'              => 'nullable|date',
        ]);

        $promotion->update(array_filter($data));

        return response()->json([
            'status'  => 'success',
            'message' => 'อัพเดทโปรโมชันสำเร็จ',
            'data'    => $promotion->fresh(),
        ]);
    }

    public function destroy(Promotion $promotion): JsonResponse
    {
        $promotion->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'ลบโปรโมชันสำเร็จ',
        ]);
    }
}