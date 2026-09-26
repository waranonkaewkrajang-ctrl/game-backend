<?php

namespace App\Console\Commands;

use App\Models\StuckBet;
use App\Services\AMBService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ตรวจหาเดิมพันที่หักเงินแล้วแต่ไม่มีผลคืนกลับมา
 * อ่านอย่างเดียว — ไม่แตะเงินลูกค้า ไม่แตะ logic เกมเดิม
 */
class CheckStuckBets extends Command
{
    protected $signature = 'bets:check-stuck
        {--minutes=15 : ตาที่ค้างนานเกินกี่นาทีถึงนับว่าผิดปกติ}
        {--hours=24 : ย้อนหลังกี่ชั่วโมง}
        {--notify : ส่งแจ้งเตือน Telegram}';

    protected $description = 'หาเดิมพันที่หักเงินแล้วไม่มีผลคืน (อ่านอย่างเดียว)';

    public function handle(AMBService $amb): int
    {
        $minutes = (int) $this->option('minutes');
        $hours   = (int) $this->option('hours');
        $notify  = (bool) $this->option('notify');

        $from = now()->subHours($hours);
        $to   = now()->subMinutes($minutes);   // ตาที่เพิ่งวางยังไม่นับ รอให้ settle มาก่อน

        $this->info("🔍 ตรวจช่วง {$from->format('Y-m-d H:i')} ถึง {$to->format('Y-m-d H:i')}");

        // หาตาที่มีแค่รายการเดียว (bet) ไม่มี win ตามมา
        $rows = DB::table('game_logs')
            ->selectRaw("
                user_id,
                provider,
                SUBSTRING_INDEX(round_id, '|', 1) AS rid,
                SUM(CASE WHEN action = 'bet' THEN bet_amount ELSE 0 END) AS bet_total,
                COUNT(*) AS cnt,
                MIN(created_at) AS bet_at,
                MIN(id) AS log_id
            ")
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('user_id', 'provider', DB::raw("SUBSTRING_INDEX(round_id, '|', 1)"))
            ->havingRaw('cnt = 1 AND bet_total > 0')
            ->orderBy('bet_at')
            ->limit(200)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('✅ ไม่พบรายการค้าง');
            return self::SUCCESS;
        }

        $this->warn("⚠️ พบ {$rows->count()} ตาที่น่าสงสัย — กำลังเช็คกับค่ายเกม...");

        $newCount = 0;
        $skipped  = 0;
        $found    = [];

        foreach ($rows as $r) {
            // เคยบันทึกแล้วข้าม
            if (StuckBet::where('provider', $r->provider)->where('round_id', $r->rid)->exists()) {
                $skipped++;
                continue;
            }

            // ดึงเลขอ้างอิงจาก log
            $log   = DB::table('game_logs')->where('id', $r->log_id)->first();
            $txns  = json_decode($log->raw_data ?? '{}', true)['txns'][0] ?? [];
            $txnId = $txns['id'] ?? null;

            // ถามค่ายว่าตานี้ผลเป็นยังไง
            [$payout, $status] = $this->askProvider($amb, $r, $txnId);

            // ค่ายบอกว่ายังไม่ปิดตา (OPEN) → ยังไม่นับว่าค้าง รอรอบหน้า
            if ($status === 'OPEN') {
                $this->line("  ⏳ {$r->rid} — ค่ายยังไม่ปิดตา ข้ามไปก่อน");
                continue;
            }

            // ค่ายบอกว่าแพ้ (payout 0 และปิดตาแล้ว) → ปกติ ไม่ใช่เงินค้าง
            if ($status === 'SETTLED' && $payout !== null && (float) $payout == 0.0) {
                $this->line("  ✔ {$r->rid} — ลูกค้าแพ้ปกติ (ค่ายคืน 0)");
                continue;
            }

            StuckBet::create([
                'user_id'    => $r->user_id,
                'provider'   => $r->provider,
                'round_id'   => $r->rid,
                'txn_id'     => $txnId,
                'bet_amount' => $r->bet_total,
                'amb_payout' => $payout,
                'amb_status' => $status,
                'status'     => 'pending',
                'bet_at'     => $r->bet_at,
            ]);

            $username = DB::table('users')->where('id', $r->user_id)->value('username');
            $found[] = sprintf("• %s | %s | หัก %.2f | ค่ายว่า: %s%s",
                $username, $r->provider, $r->bet_total, $status,
                $payout !== null ? sprintf(' (ควรคืน %.2f)', $payout) : '');

            $this->error("  ❗ {$r->rid} | {$username} | หัก {$r->bet_total} | ค่ายว่า: {$status}");
            $newCount++;
        }

        $this->newLine();
        $this->info("สรุป: พบใหม่ {$newCount} รายการ | เคยบันทึกแล้ว {$skipped} รายการ");

        if ($notify && $newCount > 0) {
            $msg = "⚠️ <b>พบเดิมพันค้าง {$newCount} รายการ</b>\n\n" . implode("\n", array_slice($found, 0, 15));
            if ($newCount > 15) $msg .= "\n\n... และอีก " . ($newCount - 15) . " รายการ";
            $msg .= "\n\nดูรายละเอียดในหลังบ้าน → เดิมพันค้าง";
            try {
                app(TelegramService::class)->send($msg);
                $this->info('📨 ส่งแจ้งเตือน Telegram แล้ว');
            } catch (\Throwable $e) {
                $this->warn('ส่ง Telegram ไม่สำเร็จ: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * ถามค่ายว่าตานี้ผลเป็นยังไง
     * คืน [payout, status] — status: SETTLED | OPEN | NOT_FOUND | ERROR
     */
    private function askProvider(AMBService $amb, $r, ?string $txnId): array
    {
        try {
            $betAt = \Carbon\Carbon::parse($r->bet_at);
            $res = $amb->getBetRecords(
                $r->provider,
                $betAt->copy()->subMinutes(10)->toIso8601String(),
                $betAt->copy()->addHours(3)->toIso8601String()
            );

            foreach ($res['data']['txns'] ?? [] as $t) {
                $matchRound = ($t['roundId'] ?? '') === $r->rid;
                $matchTxn   = $txnId && ($t['betId'] ?? '') === $txnId;
                if (!$matchRound && !$matchTxn) continue;

                $betStatus = $t['betStatus'] ?? '';
                if ($betStatus === 'OPEN' || $betStatus === 'PENDING') {
                    return [null, 'OPEN'];
                }
                return [(float) ($t['payout'] ?? 0), 'SETTLED/' . ($t['payoutStatus'] ?? '?')];
            }

            return [null, 'NOT_FOUND'];
        } catch (\Throwable $e) {
            return [null, 'ERROR'];
        }
    }
}