<?php

namespace App\Services;

use App\Models\GameLog;
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
            $winAmount = (float) $data['win_amount'];

            // แพ้ (win_amount = 0) → บันทึก game_log ปิด round แต่ไม่บวกเงิน
            if ($winAmount <= 0) {
                GameLog::create([
                    'user_id'        => $user->id,
                    'provider'       => $data['provider'],
                    'game_id'        => $data['game_id'],
                    'round_id'       => $data['round_id'] . '_win',
                    'action'         => 'win',
                    'bet_amount'     => 0,
                    'win_amount'     => 0,
                    'balance_before' => $this->walletService->getBalance($user),
                    'balance_after'  => $this->walletService->getBalance($user),
                    'raw_data'       => $data['raw'] ?? [],
                ]);

                return [
                    'status'  => 'success',
                    'balance' => $this->walletService->getBalance($user),
                ];
            }

            $transaction = $this->walletService->win(
                $user,
                $winAmount,
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

    public function validateSignature(string $payload, string $signature, string $secretKey): bool
    {
        $expected = md5($payload . $secretKey);
        return hash_equals($expected, $signature);
    }
}