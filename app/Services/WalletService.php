<?php

namespace App\Services;

use App\Models\PromotionClaim;
use App\Models\Setting;
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
    $this->validateBetAmount($amount);

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

    /**
 * ให้โบนัส + สร้าง PromotionClaim
 *
 * @param array $options เงื่อนไขที่แอดมินตั้งเอง:
 *   - turnover_multiplier : float       (ไม่ส่ง → อ่านจาก settings)
 *   - type                : string      (free_credit / deposit_bonus / spin_reward / manual)
 *   - promotion_id        : int|null
 *   - deposit_id          : int|null
 *   - expired_at          : string|null (เช่น '2026-09-01 23:59:59')
 *   - note                : string|null
 */
public function addBonus(
    User $user,
    float $amount,
    string $description = 'โบนัส',
    array $meta = [],
    ?int $adminId = null,
    array $options = [],
): Transaction {
    $this->validateAmount($amount);

    // อ่าน multiplier: แอดมินส่งมา → ใช้เลย, ไม่ส่ง → อ่านจาก settings
    $multiplier = (float) ($options['turnover_multiplier']
        ?? Setting::getValue('default_turnover_multiplier', 1));

    $type      = $options['type']       ?? 'free_credit';
    $expiredAt = $options['expired_at'] ?? null;
    $note      = $options['note']       ?? null;

    return DB::transaction(function () use (
        $user, $amount, $description, $meta, $adminId,
        $multiplier, $type, $expiredAt, $note, $options,
    ) {
        $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->firstOrFail();

        $balanceBefore = $wallet->balance;
        $balanceAfter  = bcadd($balanceBefore, $amount, 2);

        $wallet->update([
            'balance'       => $balanceAfter,
            'bonus_balance' => bcadd($wallet->bonus_balance, $amount, 2),
        ]);

        // สร้าง PromotionClaim (ถ้า multiplier > 0)
        $turnoverRequired = bcmul($amount, $multiplier, 2);

        if ($multiplier > 0) {
            PromotionClaim::create([
                'user_id'             => $user->id,
                'promotion_id'        => $options['promotion_id'] ?? null,
                'deposit_id'          => $options['deposit_id']   ?? null,
                'type'                => $type,
                'bonus_amount'        => $amount,
                'turnover_multiplier' => $multiplier,
                'turnover_required'   => $turnoverRequired,
                'turnover_current'    => 0,
                'turnover_completed'  => false,
                'status'              => 'active',
                'granted_by'          => $adminId,
                'expired_at'          => $expiredAt,
                'note'                => $note,
            ]);
        }

        return Transaction::create([
            'user_id'        => $user->id,
            'reference_id'   => $this->generateReferenceId('BNS'),
            'type'           => 'bonus',
            'direction'      => 'in',
            'amount'         => $amount,
            'balance_before' => $balanceBefore,
            'balance_after'  => $balanceAfter,
            'description'    => $description,
            'meta'           => array_merge($meta, [
                'turnover_multiplier' => $multiplier,
                'turnover_required'   => $turnoverRequired,
            ]),
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

    // =====================================================
    //  🆕 ใส่ตรงนี้
    // =====================================================

    /**
     * เช็คว่า user มีเทิร์นค้างหรือไม่ — ใช้ตอนกดถอน
     */
    public function checkTurnover(User $user): array
    {
        $activeClaims = $user->promotionClaims()
            ->where('status', 'active')
            ->where('turnover_completed', false)
            ->get();

        // Auto-expire ตัวที่หมดอายุ
        $activeClaims->each(function ($claim) {
            if ($claim->isExpired()) {
                $claim->update(['status' => 'expired']);
            }
        });

        // กรองเหลือเฉพาะ active จริงๆ
        $validClaims = $activeClaims->where('status', 'active');

        return [
            'has_active'      => $validClaims->isNotEmpty(),
            'claims'          => $validClaims,
            'total_remaining' => (float) $validClaims->sum(fn ($c) => $c->remaining),
        ];
    }

    private function updateTurnover(User $user, float $betAmount): void
{
    $activeClaims = $user->promotionClaims()
        ->where('status', 'active')
        ->where('turnover_completed', false)
        ->get();

    foreach ($activeClaims as $claim) {
        // เช็คหมดอายุก่อนนับ
        if ($claim->isExpired()) {
            $claim->update(['status' => 'expired']);
            continue;
        }

        $newTurnover = bcadd($claim->turnover_current, $betAmount, 2);
        $completed   = $newTurnover >= $claim->turnover_required;

        $claim->update([
            'turnover_current'   => $newTurnover,
            'turnover_completed' => $completed,
            'status'             => $completed ? 'completed' : 'active',
            'completed_at'       => $completed ? now() : null,
        ]);
    }
}

            private function validateAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('จำนวนเงินต้องมากกว่า 0');
        }
    }

    private function validateBetAmount(float $amount): void
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('จำนวนเงินเดิมพันต้องไม่ติดลบ');
        }
    }

    private function generateReferenceId(string $prefix): string
    {
        return $prefix . '-' . now()->format('Ymd') . '-' . strtoupper(Str::random(10));
    }
}