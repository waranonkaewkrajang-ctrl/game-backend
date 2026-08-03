<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SpinWheelHistory;
use App\Models\SpinWheelMultiplier;
use App\Models\SpinWheelPrize;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpinWheelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = Wallet::where('user_id', $user->id)->first();

        $prizes = SpinWheelPrize::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'label', 'type', 'value', 'color', 'icon', 'image_url', 'sort_order']);

        $multipliers = SpinWheelMultiplier::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'label', 'value', 'color', 'sort_order']);

        $todaySpins = SpinWheelHistory::where('user_id', $user->id)
            ->whereDate('created_at', today())
            ->count();

        $dailyLimit = (int) Setting::getValue('spin_wheel_daily_limit', 3);
        $enabled = Setting::getValue('spin_wheel_enabled', 'true') === 'true';

        return response()->json([
            'status' => 'success',
            'data' => [
                'prizes'         => $prizes,
                'multipliers'    => $multipliers,
                'today_spins'    => $todaySpins,
                'daily_limit'    => $dailyLimit,
                'remaining'      => max(0, $dailyLimit - $todaySpins),
                'enabled'        => $enabled,
                'condition'      => Setting::getValue('spin_wheel_condition', 'free_daily'),
                'deposit_min'    => (float) Setting::getValue('spin_wheel_deposit_min', 0),
                'ticket_balance' => (int) ($wallet->ticket_balance ?? 0),
                'point_balance'  => (float) ($wallet->point_balance ?? 0),
                'ticket_cost'    => (int) Setting::getValue('spin_ticket_cost', 1),
                'point_cost'     => (int) Setting::getValue('spin_point_cost', 500),
                'free_enabled'   => Setting::getValue('spin_free_enabled', 'true') === 'true',
            ],
        ]);
    }

    public function spin(Request $request, WalletService $walletService): JsonResponse
    {
        $user = $request->user();
        $spinType = $request->input('spin_type', 'free');

        if (Setting::getValue('spin_wheel_enabled', 'true') !== 'true') {
            return response()->json(['message' => 'วงล้อปิดให้บริการชั่วคราว'], 422);
        }

        $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

        // === ตรวจสอบสิทธิ์ตามประเภท ===
        if ($spinType === 'free') {
            if (Setting::getValue('spin_free_enabled', 'true') !== 'true') {
                return response()->json(['message' => 'ไม่เปิดให้หมุนฟรี'], 422);
            }

            $dailyLimit = (int) Setting::getValue('spin_wheel_daily_limit', 3);
            $todayFreeSpins = SpinWheelHistory::where('user_id', $user->id)
                ->where('spin_type', 'free')
                ->whereDate('created_at', today())
                ->count();

            if ($todayFreeSpins >= $dailyLimit) {
                return response()->json(['message' => "หมุนฟรีได้สูงสุด {$dailyLimit} ครั้ง/วัน"], 422);
            }

            $condition = Setting::getValue('spin_wheel_condition', 'free_daily');
            if ($condition === 'deposit_min') {
                $minDeposit = (float) Setting::getValue('spin_wheel_deposit_min', 100);
                $todayDeposit = \App\Models\Deposit::where('user_id', $user->id)
                    ->where('status', 'approved')
                    ->whereDate('created_at', today())
                    ->sum('amount');
                if ($todayDeposit < $minDeposit) {
                    return response()->json(['message' => "ฝากขั้นต่ำ {$minDeposit} บาท ถึงหมุนฟรีได้"], 422);
                }
            }

        } elseif ($spinType === 'ticket') {
            $ticketCost = (int) Setting::getValue('spin_ticket_cost', 1);
            if ($wallet->ticket_balance < $ticketCost) {
                return response()->json(['message' => "ตั๋วไม่พอ (ต้องใช้ {$ticketCost} ใบ)"], 422);
            }
            $wallet->decrement('ticket_balance', $ticketCost);

        } elseif ($spinType === 'points') {
            $pointCost = (float) Setting::getValue('spin_point_cost', 500);
            if ($wallet->point_balance < $pointCost) {
                return response()->json(['message' => "คะแนนไม่พอ (ต้องใช้ {$pointCost} คะแนน)"], 422);
            }
            $wallet->decrement('point_balance', $pointCost);

        } else {
            return response()->json(['message' => 'ประเภทการหมุนไม่ถูกต้อง'], 422);
        }

        // === สุ่มรางวัล ===
        $prizes = SpinWheelPrize::where('is_active', true)->get();
        if ($prizes->isEmpty()) {
            return response()->json(['message' => 'ยังไม่มีรางวัลในวงล้อ'], 422);
        }
        $prize = $this->randomByWeight($prizes);

        // === สุ่มตัวคูณ ===
        $multiplierValue = 1;
        $multipliers = SpinWheelMultiplier::where('is_active', true)->get();
        if ($multipliers->isNotEmpty()) {
            $multiplier = $this->randomByWeight($multipliers);
            $multiplierValue = $multiplier->value;
        }

        $finalValue = $prize->value * $multiplierValue;

        // === บันทึกประวัติ ===
        $history = SpinWheelHistory::create([
            'user_id'     => $user->id,
            'prize_id'    => $prize->id,
            'prize_label' => $prize->label,
            'prize_type'  => $prize->type,
            'prize_value' => $prize->value,
            'is_claimed'  => false,
            'spin_type'   => $spinType,
            'multiplier'  => $multiplierValue,
            'final_value' => $finalValue,
        ]);

        // === ให้รางวัลตาม type ===
        $message = '';

        switch ($prize->type) {
            case 'credit':
                if ($finalValue > 0) {
                    $walletService->deposit($user, $finalValue, "วงล้อ: {$prize->label} ×{$multiplierValue}", ['spin_history_id' => $history->id]);
                    $history->update(['is_claimed' => true]);
                }
                $message = "ได้รับ {$finalValue} บาท!";
                break;

            case 'bonus':
                if ($finalValue > 0) {
                    $walletService->deposit($user, $finalValue, "วงล้อโบนัส: {$prize->label} ×{$multiplierValue}", ['spin_history_id' => $history->id, 'is_bonus' => true]);
                    $history->update(['is_claimed' => true]);
                }
                $message = "ได้รับโบนัส {$finalValue} บาท!";
                break;

            case 'free_spin':
                $ticketsBack = (int) max(1, $prize->value);
                $wallet->increment('ticket_balance', $ticketsBack);
                $history->update(['is_claimed' => true]);
                $message = "ได้ตั๋วหมุนฟรี {$ticketsBack} ใบ!";
                break;

            case 'physical':
                $history->update(['is_claimed' => false]);
                $message = "ยินดีด้วย! ได้รับ {$prize->label} — แอดมินจะติดต่อกลับ";
                break;

            case 'nothing':
                $history->update(['is_claimed' => true]);
                $message = 'เสียใจด้วย ไม่ได้รางวัล';
                break;

            default:
                $history->update(['is_claimed' => true]);
                $message = "ได้รับ {$prize->label}";
                break;
        }

        $wallet->refresh();

        return response()->json([
            'status' => 'success',
            'data' => [
                'prize'          => $prize,
                'multiplier'     => $multiplierValue,
                'final_value'    => $finalValue,
                'message'        => $message,
                'history'        => $history,
                'ticket_balance' => (int) $wallet->ticket_balance,
                'point_balance'  => (float) $wallet->point_balance,
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

    public function recentWinners(): JsonResponse
    {
        $winners = SpinWheelHistory::with('user:id,username,phone')
            ->where('prize_type', '!=', 'nothing')
            ->where('final_value', '>', 0)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($h) {
                $username = $h->user->username ?? 'User';
                $masked = mb_substr($username, 0, 3) . '****';
                $phone = $h->user->phone ?? '';
                $maskedPhone = $phone ? substr($phone, -4) : '';

                return [
                    'id'          => $h->id,
                    'username'    => $masked,
                    'phone_tail'  => $maskedPhone,
                    'prize_label' => $h->prize_label,
                    'prize_type'  => $h->prize_type,
                    'final_value' => (float) $h->final_value,
                    'multiplier'  => (float) $h->multiplier,
                    'image_url'   => $h->prize->image_url ?? null,
                    'created_at'  => $h->created_at->diffForHumans(),
                ];
            });

        return response()->json(['status' => 'success', 'data' => $winners]);
    }

    private function randomByWeight($items)
    {
        $totalWeight = $items->sum('probability');
        $random = mt_rand(0, (int)($totalWeight * 100)) / 100;

        $cumulative = 0;
        foreach ($items as $item) {
            $cumulative += $item->probability;
            if ($random <= $cumulative) {
                return $item;
            }
        }

        return $items->last();
    }
}