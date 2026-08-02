<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SpinWheelHistory;
use App\Models\SpinWheelPrize;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpinWheelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $prizes = SpinWheelPrize::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'label', 'type', 'value', 'color', 'icon', 'sort_order']);

        $todaySpins = SpinWheelHistory::where('user_id', $user->id)
            ->whereDate('created_at', today())
            ->count();

        $dailyLimit = (int) Setting::getValue('spin_wheel_daily_limit', 3);
        $enabled = Setting::getValue('spin_wheel_enabled', 'true') === 'true';

        return response()->json([
            'status' => 'success',
            'data' => [
                'prizes'       => $prizes,
                'today_spins'  => $todaySpins,
                'daily_limit'  => $dailyLimit,
                'remaining'    => max(0, $dailyLimit - $todaySpins),
                'enabled'      => $enabled,
                'condition'    => Setting::getValue('spin_wheel_condition', 'free_daily'),
                'deposit_min'  => (float) Setting::getValue('spin_wheel_deposit_min', 0),
            ],
        ]);
    }

    public function spin(Request $request, WalletService $walletService): JsonResponse
    {
        $user = $request->user();

        // เช็คเปิดใช้งาน
        if (Setting::getValue('spin_wheel_enabled', 'true') !== 'true') {
            return response()->json(['message' => 'วงล้อปิดให้บริการชั่วคราว'], 422);
        }

        // เช็คจำนวนครั้งต่อวัน
        $dailyLimit = (int) Setting::getValue('spin_wheel_daily_limit', 3);
        $todaySpins = SpinWheelHistory::where('user_id', $user->id)
            ->whereDate('created_at', today())
            ->count();

        if ($todaySpins >= $dailyLimit) {
            return response()->json(['message' => "หมุนได้สูงสุด {$dailyLimit} ครั้ง/วัน"], 422);
        }

        // เช็คเงื่อนไข
        $condition = Setting::getValue('spin_wheel_condition', 'free_daily');
        if ($condition === 'deposit_min') {
            $minDeposit = (float) Setting::getValue('spin_wheel_deposit_min', 100);
            $todayDeposit = \App\Models\Deposit::where('user_id', $user->id)
                ->where('status', 'approved')
                ->whereDate('created_at', today())
                ->sum('amount');
            if ($todayDeposit < $minDeposit) {
                return response()->json(['message' => "ฝากขั้นต่ำ {$minDeposit} บาท ถึงหมุนได้"], 422);
            }
        }

        // สุ่มรางวัลตาม probability
        $prizes = SpinWheelPrize::where('is_active', true)->get();
        if ($prizes->isEmpty()) {
            return response()->json(['message' => 'ยังไม่มีรางวัลในวงล้อ'], 422);
        }

        $prize = $this->randomPrize($prizes);

        // บันทึกประวัติ
        $history = SpinWheelHistory::create([
            'user_id'     => $user->id,
            'prize_id'    => $prize->id,
            'prize_label' => $prize->label,
            'prize_type'  => $prize->type,
            'prize_value' => $prize->value,
            'is_claimed'  => false,
        ]);

        // ให้รางวัลอัตโนมัติ (credit/bonus)
        if ($prize->type === 'credit' && $prize->value > 0) {
            $walletService->deposit(
                $user,
                $prize->value,
                'วงล้อ: ' . $prize->label,
                ['spin_history_id' => $history->id]
            );
            $history->update(['is_claimed' => true]);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'prize'     => $prize,
                'history'   => $history,
                'remaining' => max(0, $dailyLimit - $todaySpins - 1),
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $history = SpinWheelHistory::where('user_id', $request->user()->id)
            ->with('prize')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json(['status' => 'success', 'data' => $history]);
    }

    private function randomPrize($prizes): SpinWheelPrize
    {
        $totalWeight = $prizes->sum('probability');
        $random = mt_rand(0, (int)($totalWeight * 100)) / 100;

        $cumulative = 0;
        foreach ($prizes as $prize) {
            $cumulative += $prize->probability;
            if ($random <= $cumulative) {
                return $prize;
            }
        }

        return $prizes->last();
    }
}