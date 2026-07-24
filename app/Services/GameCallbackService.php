<?php

namespace App\Services;

use App\Models\GameLog;
use App\Models\Reward;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class GameCallbackService
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    public function getBalance(string $username): array
    {
        $user = User::where('amb_username', $username)->first();

        if (!$user) {
            return ['status' => 'error', 'message' => 'USER_NOT_FOUND'];
        }

        return [
            'status'  => 'success',
            'balance' => $this->walletService->getBalance($user),
        ];
    }

    public function processBet(array $data): array
    {
        $user = User::where('amb_username', $data['username'])->first();

        if (!$user || !$user->isActive()) {
            return ['status' => 'error', 'message' => 'USER_NOT_FOUND'];
        }

        $existingLog = GameLog::where('round_id', $data['round_id'])
            ->where('action', 'bet')
            ->first();

        if ($existingLog) {
            Log::warning('Duplicate bet callback', ['round_id' => $data['round_id']]);
            return [
                'status'  => 'success',
                'balance' => $this->walletService->getBalance($user),
                'message' => 'DUPLICATE',
            ];
        }

        try {
            $transaction = $this->walletService->bet(
                $user,
                (float) $data['bet_amount'],
                $data['round_id'],
                $data['game_id'],
                $data['provider'],
                $data['raw'] ?? []
            );

            // === คำนวณค่าแนะนำเพื่อน ===
            $this->processReferralCommission($user, (float) $data['bet_amount'], $data['provider'], $data['game_id']);

            return [
                'status'  => 'success',
                'balance' => (float) $transaction->balance_after,
            ];
        } catch (\Exception $e) {
            Log::error('Bet failed', [
                'user'     => $data['username'],
                'round_id' => $data['round_id'],
                'error'    => $e->getMessage(),
            ]);

            return [
                'status'  => 'error',
                'message' => $e->getMessage(),
                'balance' => $this->walletService->getBalance($user),
            ];
        }
    }

    public function processWin(array $data): array
    {
        $user = User::where('amb_username', $data['username'])->first();

        if (!$user) {
            return ['status' => 'error', 'message' => 'USER_NOT_FOUND'];
        }

        $existingLog = GameLog::where('round_id', $data['round_id'] . '_win')
            ->where('action', 'win')
            ->first();

        if ($existingLog) {
            Log::warning('Duplicate win callback', ['round_id' => $data['round_id']]);
            return [
                'status'  => 'success',
                'balance' => $this->walletService->getBalance($user),
                'message' => 'DUPLICATE',
            ];
        }

        try {
            $transaction = $this->walletService->win(
                $user,
                (float) $data['win_amount'],
                $data['round_id'],
                $data['game_id'],
                $data['provider'],
                $data['raw'] ?? []
            );

            return [
                'status'  => 'success',
                'balance' => (float) $transaction->balance_after,
            ];
        } catch (\Exception $e) {
            Log::error('Win failed', [
                'user'     => $data['username'],
                'round_id' => $data['round_id'],
                'error'    => $e->getMessage(),
            ]);

            return [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * คำนวณค่าแนะนำเพื่อน — เก็บเป็นรางวัลรอรับ
     */
    private function processReferralCommission(User $user, float $betAmount, string $provider, string $gameId): void
    {
        try {
            // เช็คว่ามีคนแนะนำไหม
            if (!$user->referred_by) return;

            $referrer = User::find($user->referred_by);
            if (!$referrer || !$referrer->isActive()) return;

            // ดึง % ค่าแนะนำ
            $percent = (float) Setting::getValue('referral_percent', 0);
            if ($percent <= 0) return;

            $commission = round($betAmount * ($percent / 100), 2);
            if ($commission < 0.01) return;

            // เก็บเป็นรางวัลรอรับ (ไม่จ่ายตรงเข้ากระเป๋า)
            Reward::create([
                'user_id'     => $referrer->id,
                'type'        => 'referral',
                'amount'      => $commission,
                'status'      => 'pending',
                'description' => "ค่าแนะนำ {$user->username} เดิมพัน {$provider} ({$percent}%)",
                'meta'        => [
                    'from_user_id'  => $user->id,
                    'from_username' => $user->username,
                    'bet_amount'    => $betAmount,
                    'percent'       => $percent,
                    'provider'      => $provider,
                    'game_id'       => $gameId,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error("Referral commission failed: " . $e->getMessage(), [
                'user_id'   => $user->id,
                'referrer'  => $user->referred_by,
                'betAmount' => $betAmount,
            ]);
        }
    }

    public function validateSignature(string $payload, string $signature, string $secretKey): bool
    {
        $expected = md5($payload . $secretKey);
        return hash_equals($expected, $signature);
    }
}