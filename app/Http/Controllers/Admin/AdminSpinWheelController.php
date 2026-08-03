<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SpinWheelHistory;
use App\Models\SpinWheelMultiplier;
use App\Models\SpinWheelPrize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSpinWheelController extends Controller
{
    // === รางวัล (Prizes) ===

    public function prizes(): JsonResponse
    {
        $prizes = SpinWheelPrize::orderBy('sort_order')->get();
        return response()->json(['status' => 'success', 'data' => $prizes]);
    }

    public function storePrize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label'       => 'required|string|max:50',
            'type'        => 'required|in:credit,bonus,free_spin,nothing,physical',
            'value'       => 'required|numeric|min:0',
            'color'       => 'required|string|max:20',
            'icon'        => 'nullable|string|max:10',
            'image_url'   => 'nullable|string|max:500',
            'probability' => 'required|numeric|min:0|max:100',
            'sort_order'  => 'nullable|integer',
            'is_active'   => 'nullable|boolean',
        ]);

        $prize = SpinWheelPrize::create($data);
        return response()->json(['status' => 'success', 'data' => $prize], 201);
    }

    public function updatePrize(Request $request, int $id): JsonResponse
    {
        $prize = SpinWheelPrize::findOrFail($id);

        $data = $request->validate([
            'label'       => 'sometimes|string|max:50',
            'type'        => 'sometimes|in:credit,bonus,free_spin,nothing,physical',
            'value'       => 'sometimes|numeric|min:0',
            'color'       => 'sometimes|string|max:20',
            'icon'        => 'nullable|string|max:10',
            'image_url'   => 'nullable|string|max:500',
            'probability' => 'sometimes|numeric|min:0|max:100',
            'sort_order'  => 'nullable|integer',
            'is_active'   => 'nullable|boolean',
        ]);

        $prize->update($data);
        return response()->json(['status' => 'success', 'data' => $prize]);
    }

    public function destroyPrize(int $id): JsonResponse
    {
        SpinWheelPrize::findOrFail($id)->delete();
        return response()->json(['status' => 'success', 'message' => 'ลบรางวัลแล้ว']);
    }

    // === ตัวคูณ (Multipliers) ===

    public function multipliers(): JsonResponse
    {
        $multipliers = SpinWheelMultiplier::orderBy('sort_order')->get();
        return response()->json(['status' => 'success', 'data' => $multipliers]);
    }

    public function storeMultiplier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label'       => 'required|string|max:20',
            'value'       => 'required|numeric|min:0.01',
            'color'       => 'required|string|max:20',
            'probability' => 'required|numeric|min:0|max:100',
            'sort_order'  => 'nullable|integer',
            'is_active'   => 'nullable|boolean',
        ]);

        $multiplier = SpinWheelMultiplier::create($data);
        return response()->json(['status' => 'success', 'data' => $multiplier], 201);
    }

    public function updateMultiplier(Request $request, int $id): JsonResponse
    {
        $multiplier = SpinWheelMultiplier::findOrFail($id);

        $data = $request->validate([
            'label'       => 'sometimes|string|max:20',
            'value'       => 'sometimes|numeric|min:0.01',
            'color'       => 'sometimes|string|max:20',
            'probability' => 'sometimes|numeric|min:0|max:100',
            'sort_order'  => 'nullable|integer',
            'is_active'   => 'nullable|boolean',
        ]);

        $multiplier->update($data);
        return response()->json(['status' => 'success', 'data' => $multiplier]);
    }

    public function destroyMultiplier(int $id): JsonResponse
    {
        SpinWheelMultiplier::findOrFail($id)->delete();
        return response()->json(['status' => 'success', 'message' => 'ลบตัวคูณแล้ว']);
    }

    // === ตั้งค่า (Settings) ===

    public function settings(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'enabled'      => Setting::getValue('spin_wheel_enabled', 'true') === 'true',
                'condition'    => Setting::getValue('spin_wheel_condition', 'free_daily'),
                'deposit_min'  => (float) Setting::getValue('spin_wheel_deposit_min', 0),
                'daily_limit'  => (int) Setting::getValue('spin_wheel_daily_limit', 3),
                'ticket_cost'  => (int) Setting::getValue('spin_ticket_cost', 1),
                'point_cost'   => (int) Setting::getValue('spin_point_cost', 500),
                'free_enabled' => Setting::getValue('spin_free_enabled', 'true') === 'true',
            ],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'      => 'required|boolean',
            'condition'    => 'required|in:free_daily,deposit_min',
            'deposit_min'  => 'required|numeric|min:0',
            'daily_limit'  => 'required|integer|min:1|max:100',
            'ticket_cost'  => 'required|integer|min:1',
            'point_cost'   => 'required|integer|min:1',
            'free_enabled' => 'required|boolean',
        ]);

        Setting::setValue('spin_wheel_enabled', $data['enabled'] ? 'true' : 'false');
        Setting::setValue('spin_wheel_condition', $data['condition']);
        Setting::setValue('spin_wheel_deposit_min', $data['deposit_min']);
        Setting::setValue('spin_wheel_daily_limit', $data['daily_limit']);
        Setting::setValue('spin_ticket_cost', $data['ticket_cost']);
        Setting::setValue('spin_point_cost', $data['point_cost']);
        Setting::setValue('spin_free_enabled', $data['free_enabled'] ? 'true' : 'false');

        return response()->json(['status' => 'success', 'message' => 'บันทึกตั้งค่าแล้ว']);
    }

    // === ประวัติ & สรุป ===

    public function history(Request $request): JsonResponse
    {
        $history = SpinWheelHistory::with('user', 'prize')
            ->when($request->user_id, fn($q, $id) => $q->where('user_id', $id))
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json(['status' => 'success', 'data' => $history]);
    }

    public function summary(): JsonResponse
    {
        $today = now()->startOfDay();

        return response()->json([
            'status' => 'success',
            'data' => [
                'today_spins'    => SpinWheelHistory::where('created_at', '>=', $today)->count(),
                'today_credit'   => SpinWheelHistory::where('created_at', '>=', $today)->where('prize_type', 'credit')->sum('final_value'),
                'total_spins'    => SpinWheelHistory::count(),
                'total_credit'   => SpinWheelHistory::where('prize_type', 'credit')->sum('final_value'),
                'total_physical' => SpinWheelHistory::where('prize_type', 'physical')->where('is_claimed', false)->count(),
                'prizes_count'   => SpinWheelPrize::where('is_active', true)->count(),
            ],
        ]);
    }

    // === จัดการตั๋ว ===

    public function giveTickets(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'amount'  => 'required|integer|min:1|max:9999',
        ]);

        $wallet = \App\Models\Wallet::where('user_id', $data['user_id'])->firstOrFail();
        $wallet->increment('ticket_balance', $data['amount']);

        return response()->json([
            'status'  => 'success',
            'message' => "เพิ่มตั๋ว {$data['amount']} ใบ ให้ user #{$data['user_id']} แล้ว",
            'data'    => ['ticket_balance' => $wallet->fresh()->ticket_balance],
        ]);
    }
}