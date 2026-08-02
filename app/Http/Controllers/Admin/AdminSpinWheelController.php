<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SpinWheelHistory;
use App\Models\SpinWheelPrize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSpinWheelController extends Controller
{
    // ดูรางวัลทั้งหมด
    public function prizes(): JsonResponse
    {
        $prizes = SpinWheelPrize::orderBy('sort_order')->get();
        return response()->json(['status' => 'success', 'data' => $prizes]);
    }

    // เพิ่มรางวัล
    public function storePrize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label'       => 'required|string|max:50',
            'type'        => 'required|in:credit,bonus,free_spin,nothing',
            'value'       => 'required|numeric|min:0',
            'color'       => 'required|string|max:20',
            'icon'        => 'nullable|string|max:10',
            'probability' => 'required|numeric|min:0|max:100',
            'sort_order'  => 'nullable|integer',
            'is_active'   => 'nullable|boolean',
        ]);

        $prize = SpinWheelPrize::create($data);
        return response()->json(['status' => 'success', 'data' => $prize], 201);
    }

    // แก้ไขรางวัล
    public function updatePrize(Request $request, int $id): JsonResponse
    {
        $prize = SpinWheelPrize::findOrFail($id);

        $data = $request->validate([
            'label'       => 'sometimes|string|max:50',
            'type'        => 'sometimes|in:credit,bonus,free_spin,nothing',
            'value'       => 'sometimes|numeric|min:0',
            'color'       => 'sometimes|string|max:20',
            'icon'        => 'nullable|string|max:10',
            'probability' => 'sometimes|numeric|min:0|max:100',
            'sort_order'  => 'nullable|integer',
            'is_active'   => 'nullable|boolean',
        ]);

        $prize->update($data);
        return response()->json(['status' => 'success', 'data' => $prize]);
    }

    // ลบรางวัล
    public function destroyPrize(int $id): JsonResponse
    {
        SpinWheelPrize::findOrFail($id)->delete();
        return response()->json(['status' => 'success', 'message' => 'ลบรางวัลแล้ว']);
    }

    // ตั้งค่าวงล้อ
    public function settings(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'enabled'     => Setting::getValue('spin_wheel_enabled', 'true') === 'true',
                'condition'   => Setting::getValue('spin_wheel_condition', 'free_daily'),
                'deposit_min' => (float) Setting::getValue('spin_wheel_deposit_min', 0),
                'daily_limit' => (int) Setting::getValue('spin_wheel_daily_limit', 3),
            ],
        ]);
    }

    // บันทึกตั้งค่า
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled'     => 'required|boolean',
            'condition'   => 'required|in:free_daily,deposit_min',
            'deposit_min' => 'required|numeric|min:0',
            'daily_limit' => 'required|integer|min:1|max:100',
        ]);

        Setting::setValue('spin_wheel_enabled', $data['enabled'] ? 'true' : 'false');
        Setting::setValue('spin_wheel_condition', $data['condition']);
        Setting::setValue('spin_wheel_deposit_min', $data['deposit_min']);
        Setting::setValue('spin_wheel_daily_limit', $data['daily_limit']);

        return response()->json(['status' => 'success', 'message' => 'บันทึกตั้งค่าแล้ว']);
    }

    // ประวัติการหมุนทั้งหมด
    public function history(Request $request): JsonResponse
    {
        $history = SpinWheelHistory::with('user', 'prize')
            ->when($request->user_id, fn($q, $id) => $q->where('user_id', $id))
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json(['status' => 'success', 'data' => $history]);
    }

    // สรุปยอด
    public function summary(): JsonResponse
    {
        $today = now()->startOfDay();

        return response()->json([
            'status' => 'success',
            'data' => [
                'today_spins'   => SpinWheelHistory::where('created_at', '>=', $today)->count(),
                'today_credit'  => SpinWheelHistory::where('created_at', '>=', $today)->where('prize_type', 'credit')->sum('prize_value'),
                'total_spins'   => SpinWheelHistory::count(),
                'total_credit'  => SpinWheelHistory::where('prize_type', 'credit')->sum('prize_value'),
                'prizes_count'  => SpinWheelPrize::where('is_active', true)->count(),
            ],
        ]);
    }
}