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

        // 🆕 ใช้ txn_id เช็ค duplicate (AMB ส่งหลาย txn ใน round เดียวกันได้)
$txnId = $data['txn_id'] ?? null;
$logRoundId = $txnId ? $data['round_id'] . '|' . $txnId : $data['round_id'];

$existingLog = GameLog::where('round_id', $logRoundId)
    ->where('action', 'bet')
    ->first();

if ($existingLog) {
    Log::warning('Duplicate bet callback', ['round_id' => $data['round_id'], 'txn_id' => $txnId]);
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
        $logRoundId,
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

        $txnId = $data['txn_id'] ?? null;
        $logRoundId = $txnId ? $data['round_id'] . '|' . $txnId : $data['round_id'];

        $existingLog = GameLog::where('round_id', $logRoundId . '_win')
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
                    'round_id'       => $logRoundId . '_win',
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
                $logRoundId,
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
     * ยกเลิกเดิมพัน + คืนเงิน (callback: cancelBets)
     * REFUND = ยกเลิกหลังวางแล้ว | REJECT = ค่ายปฏิเสธ (กีฬา)
     * ไม่แตะ processBet / processWin เดิม
     */
    public function processCancel(array $data): array
    {
        $user = User::where('amb_username', $data['username'])->first();

        if (!$user) {
            return ['status' => 'error', 'message' => 'USER_NOT_FOUND'];
        }

        $txnId      = $data['txn_id'] ?? null;
        $roundId    = $data['round_id'];
        $logRoundId = $txnId ? $roundId . '|' . $txnId : $roundId;
        $byRound    = ($data['transaction_type'] ?? 'BY_TRANSACTION') === 'BY_ROUND';

        // หารายการ bet ที่ต้องคืน
        $betLogs = GameLog::where('user_id', $user->id)
            ->where('action', 'bet')
            ->when($byRound,
                fn ($q) => $q->where(fn ($s) => $s->where('round_id', $roundId)->orWhere('round_id', 'like', $roundId . '|%')),
                fn ($q) => $q->where('round_id', $logRoundId)
            )
            ->get();

        if ($betLogs->isEmpty()) {
            Log::warning('Cancel: ไม่พบรายการเดิมพัน', ['round_id' => $roundId, 'txn_id' => $txnId]);
            return [
                'status'  => 'success',                      // ตอบสำเร็จ ไม่ให้ค่ายยิงซ้ำไม่จบ
                'balance' => $this->walletService->getBalance($user),
                'message' => 'BET_NOT_FOUND',
            ];
        }

        $refunded = 0.0;

        foreach ($betLogs as $bet) {
            $base = $bet->round_id;

            // เคยคืนไปแล้ว → ข้าม (กันคืนซ้ำ)
            if (GameLog::where('round_id', $base . '_cancel')->exists()) {
                Log::warning('Cancel ซ้ำ ข้าม', ['round_id' => $base]);
                continue;
            }

            // settle ไปแล้ว → ไม่คืน (ลูกค้าได้ผลไปแล้ว)
            if (GameLog::where('round_id', $base . '_win')->exists()) {
                Log::warning('Cancel: ตานี้ settle แล้ว ไม่คืน', ['round_id' => $base]);
                continue;
            }

            try {
                $amount = (float) $bet->bet_amount;
                if ($amount <= 0) continue;

                $tx = $this->walletService->win(
                    $user,
                    $amount,
                    $base . '_cancel',               // กลายเป็น {base}_cancel_win ใน GameLog
                    $bet->game_id,
                    $bet->provider,
                    $data['raw'] ?? []
                );

                $refunded += $amount;

                Log::info('คืนเงินจาก cancelBets', [
                    'user'     => $data['username'],
                    'round_id' => $base,
                    'amount'   => $amount,
                    'status'   => $data['cancel_status'] ?? '?',
                    'balance'  => $tx->balance_after,
                ]);
            } catch (\Exception $e) {
                Log::error('คืนเงินไม่สำเร็จ', ['round_id' => $base, 'error' => $e->getMessage()]);
                return [
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                    'balance' => $this->walletService->getBalance($user),
                ];
            }
        }

        return [
            'status'   => 'success',
            'balance'  => $this->walletService->getBalance($user),
            'refunded' => $refunded,
        ];
    }

        /**
     * ปรับยอดเดิมพัน (callback: adjustBets)
     * สูตรตามเอกสาร: ยอดใหม่ = ยอดปัจจุบัน + ยอดเดิม - ยอดที่ค่ายส่งมา
     * ไม่แตะ processBet / processWin / processCancel เดิม
     */
    public function processAdjust(array $data): array
    {
        $user = User::where('amb_username', $data['username'])->first();

        if (!$user) {
            return ['status' => 'error', 'message' => 'USER_NOT_FOUND'];
        }

        $txnId      = $data['txn_id'] ?? null;
        $roundId    = $data['round_id'];
        $logRoundId = $txnId ? $roundId . '|' . $txnId : $roundId;
        $newAmount  = (float) ($data['bet_amount'] ?? 0);

        // หารายการเดิมพันเดิม
        $betLog = GameLog::where('user_id', $user->id)
            ->where('action', 'bet')
            ->where('round_id', $logRoundId)
            ->first();

        if (!$betLog) {
            Log::warning('Adjust: ไม่พบรายการเดิมพัน', ['round_id' => $roundId, 'txn_id' => $txnId]);
            return [
                'status'  => 'success',                    // ตอบสำเร็จ ไม่ให้ค่ายยิงซ้ำไม่จบ
                'balance' => $this->walletService->getBalance($user),
                'message' => 'BET_NOT_FOUND',
            ];
        }

        // settle แล้วไม่ปรับ
        if (GameLog::where('round_id', $logRoundId . '_win')->exists()) {
            Log::warning('Adjust: ตานี้ settle แล้ว ไม่ปรับ', ['round_id' => $logRoundId]);
            return [
                'status'  => 'success',
                'balance' => $this->walletService->getBalance($user),
                'message' => 'ALREADY_SETTLED',
            ];
        }

        // ยกเลิกไปแล้วไม่ปรับ
        if (GameLog::where('round_id', $logRoundId . '_cancel_win')->exists()) {
            return [
                'status'  => 'success',
                'balance' => $this->walletService->getBalance($user),
                'message' => 'ALREADY_CANCELLED',
            ];
        }

        // หายอดล่าสุดของตานี้ (เผื่อเคยปรับมาแล้ว)
        $lastAdjust = GameLog::where('user_id', $user->id)
            ->where('action', 'adjust')
            ->where('round_id', 'like', $logRoundId . '_adjust%')
            ->orderByDesc('id')
            ->first();

        $oldAmount = $lastAdjust ? (float) $lastAdjust->bet_amount : (float) $betLog->bet_amount;
        $diff      = $newAmount - $oldAmount;   // บวก = ต้องหักเพิ่ม | ลบ = ต้องคืน

        if (abs($diff) < 0.01) {
            return [
                'status'  => 'success',
                'balance' => $this->walletService->getBalance($user),
                'message' => 'NO_CHANGE',
            ];
        }

        try {
            $seq   = ($lastAdjust ? 1 : 0) + GameLog::where('round_id', 'like', $logRoundId . '_adjust%')->count();
            $tag   = $logRoundId . '_adjust' . ($seq > 0 ? $seq : '');

            if ($diff > 0) {
                // ยอดใหม่มากกว่าเดิม → หักเพิ่ม
                $tx = $this->walletService->bet($user, $diff, $tag, $betLog->game_id, $betLog->provider, $data['raw'] ?? []);
            } else {
                // ยอดใหม่น้อยกว่าเดิม → คืนส่วนต่าง
                $tx = $this->walletService->win($user, abs($diff), $tag, $betLog->game_id, $betLog->provider, $data['raw'] ?? []);
            }

            // บันทึกยอดล่าสุดไว้อ้างอิงครั้งหน้า
            GameLog::create([
                'user_id'        => $user->id,
                'provider'       => $betLog->provider,
                'game_id'        => $betLog->game_id,
                'round_id'       => $tag . '_ref',
                'action'         => 'adjust',
                'bet_amount'     => $newAmount,
                'win_amount'     => 0,
                'balance_before' => $tx->balance_before,
                'balance_after'  => $tx->balance_after,
                'raw_data'       => $data['raw'] ?? [],
            ]);

            Log::info('ปรับยอดเดิมพันจาก adjustBets', [
                'user'     => $data['username'],
                'round_id' => $logRoundId,
                'เดิม'     => $oldAmount,
                'ใหม่'     => $newAmount,
                'ส่วนต่าง' => $diff,
                'balance'  => $tx->balance_after,
            ]);

            return [
                'status'  => 'success',
                'balance' => (float) $tx->balance_after,
                'diff'    => $diff,
            ];
        } catch (\Exception $e) {
            Log::error('ปรับยอดไม่สำเร็จ', ['round_id' => $logRoundId, 'error' => $e->getMessage()]);
            return [
                'status'  => 'error',
                'message' => $e->getMessage(),
                'balance' => $this->walletService->getBalance($user),
            ];
        }
    }

    public function validateSignature(string $payload, string $signature, string $secretKey): bool
    {
        $expected = md5($payload . $secretKey);
        return hash_equals($expected, $signature);
    }
}