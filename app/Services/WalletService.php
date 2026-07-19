<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
    public function deposit(User $user, float $amount, string $description = 'ฝากเงิน', array $meta = [], ?int $adminId = null): Transaction
    {
        $this->validateAmount($amount);

        return DB::transaction(function () use ($user, $amount, $description, $meta, $adminId) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            $balanceBefore = $wallet->balance;
            $balanceAfter  = bcadd($balanceBefore, $amount, 2);

            $wallet->update([
                'balance'       => $balanceAfter,
                'total_deposit' => bcadd($wallet->total_deposit, $amount, 2),
            ]);

            return Transaction::create([
                'user_id'        => $user->id,
                'reference_id'   => $this->generateReferenceId('DEP'),
                'type'           => 'deposit',
                'direction'      => 'in',
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'description'    => $description,
                'meta'           => $meta,
                'status'         => 'completed',
                'processed_by'   => $adminId,
            ]);
        });
    }

    public function withdraw(User $user, float $amount, string $description = 'ถอนเงิน', array $meta = [], ?int $adminId = null): Transaction
    {
        $this->validateAmount($amount);

        return DB::transaction(function () use ($user, $amount, $description, $meta, $adminId) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            if (!$wallet->hasEnough($amount)) {
                throw new \Exception('ยอดเงินไม่เพียงพอ');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter  = bcsub($balanceBefore, $amount, 2);

            $wallet->update([
                'balance'        => $balanceAfter,
                'total_withdraw' => bcadd($wallet->total_withdraw, $amount, 2),
            ]);

            return Transaction::create([
                'user_id'        => $user->id,
                'reference_id'   => $this->generateReferenceId('WDR'),
                'type'           => 'withdraw',
                'direction'      => 'out',
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'description'    => $description,
                'meta'           => $meta,
                'status'         => 'completed',
                'processed_by'   => $adminId,
            ]);
        });
    }

    public function bet(User $user, float $amount, string $roundId, string $gameId, string $provider, array $rawData = []): Transaction
    {
        $this->validateAmount($amount);

        return DB::transaction(function () use ($user, $amount, $roundId, $gameId, $provider, $rawData) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            if (!$wallet->hasEnough($amount)) {
                throw new \Exception('INSUFFICIENT_BALANCE');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter  = bcsub($balanceBefore, $amount, 2);

            $wallet->update([
                'balance'   => $balanceAfter,
                'total_bet' => bcadd($wallet->total_bet, $amount, 2),
            ]);

            \App\Models\GameLog::create([
                'user_id'        => $user->id,
                'provider'       => $provider,
                'game_id'        => $gameId,
                'round_id'       => $roundId,
                'action'         => 'bet',
                'bet_amount'     => $amount,
                'win_amount'     => 0,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'raw_data'       => $rawData,
            ]);

            $this->updateTurnover($user, $amount);

            return Transaction::create([
                'user_id'        => $user->id,
                'reference_id'   => $this->generateReferenceId('BET'),
                'type'           => 'bet',
                'direction'      => 'out',
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'description'    => "เดิมพัน {$provider}:{$gameId}",
                'meta'           => ['round_id' => $roundId, 'game_id' => $gameId, 'provider' => $provider],
                'status'         => 'completed',
            ]);
        });
    }

    public function win(User $user, float $amount, string $roundId, string $gameId, string $provider, array $rawData = []): Transaction
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('จำนวนเงินต้องไม่ติดลบ');
        }

        return DB::transaction(function () use ($user, $amount, $roundId, $gameId, $provider, $rawData) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            $balanceBefore = $wallet->balance;
            $balanceAfter  = bcadd($balanceBefore, $amount, 2);

            $wallet->update([
                'balance'   => $balanceAfter,
                'total_win' => bcadd($wallet->total_win, $amount, 2),
            ]);

            \App\Models\GameLog::create([
                'user_id'        => $user->id,
                'provider'       => $provider,
                'game_id'        => $gameId,
                'round_id'       => $roundId . '_win',
                'action'         => 'win',
                'bet_amount'     => 0,
                'win_amount'     => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'raw_data'       => $rawData,
            ]);

            return Transaction::create([
                'user_id'        => $user->id,
                'reference_id'   => $this->generateReferenceId('WIN'),
                'type'           => 'win',
                'direction'      => 'in',
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'description'    => "ชนะ {$provider}:{$gameId}",
                'meta'           => ['round_id' => $roundId, 'game_id' => $gameId, 'provider' => $provider],
                'status'         => 'completed',
            ]);
        });
    }

    public function addBonus(User $user, float $amount, string $description = 'โบนัส', array $meta = [], ?int $adminId = null): Transaction
    {
        $this->validateAmount($amount);

        return DB::transaction(function () use ($user, $amount, $description, $meta, $adminId) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            $balanceBefore = $wallet->balance;
            $balanceAfter  = bcadd($balanceBefore, $amount, 2);

            $wallet->update([
                'balance'       => $balanceAfter,
                'bonus_balance' => bcadd($wallet->bonus_balance, $amount, 2),
            ]);

            return Transaction::create([
                'user_id'        => $user->id,
                'reference_id'   => $this->generateReferenceId('BNS'),
                'type'           => 'bonus',
                'direction'      => 'in',
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'description'    => $description,
                'meta'           => $meta,
                'status'         => 'completed',
                'processed_by'   => $adminId,
            ]);
        });
    }

    public function adjust(User $user, float $amount, string $description, int $adminId): Transaction
    {
        if ($amount == 0) {
            throw new \InvalidArgumentException('จำนวนเงินต้องไม่เท่ากับ 0');
        }

        return DB::transaction(function () use ($user, $amount, $description, $adminId) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcadd($balanceBefore, $amount, 2);
            $direction = $amount > 0 ? 'in' : 'out';

            if ($balanceAfter < 0) {
                throw new \Exception('ปรับลดแล้วยอดจะติดลบ');
            }

            $wallet->update(['balance' => $balanceAfter]);

            return Transaction::create([
                'user_id'        => $user->id,
                'reference_id'   => $this->generateReferenceId('ADJ'),
                'type'           => 'adjustment',
                'direction'      => $direction,
                'amount'         => abs($amount),
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'description'    => $description,
                'meta'           => ['adjusted_by' => $adminId],
                'status'         => 'completed',
                'processed_by'   => $adminId,
            ]);
        });
    }

    public function getBalance(User $user): float
    {
        $wallet = Wallet::where('user_id', $user->id)->first();
        return $wallet ? (float) $wallet->balance : 0.00;
    }

    public function createWallet(User $user): Wallet
    {
        return Wallet::create(['user_id' => $user->id]);
    }

    private function updateTurnover(User $user, float $betAmount): void
    {
        $activeClaims = $user->promotionClaims()
            ->where('status', 'active')
            ->where('turnover_completed', false)
            ->get();

        foreach ($activeClaims as $claim) {
            $newTurnover = bcadd($claim->turnover_current, $betAmount, 2);
            $completed   = $newTurnover >= $claim->turnover_required;

            $claim->update([
                'turnover_current'   => $newTurnover,
                'turnover_completed' => $completed,
                'status'             => $completed ? 'completed' : 'active',
            ]);
        }
    }

    private function validateAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('จำนวนเงินต้องมากกว่า 0');
        }
    }

    private function generateReferenceId(string $prefix): string
    {
        return $prefix . '-' . now()->format('Ymd') . '-' . strtoupper(Str::random(10));
    }
}