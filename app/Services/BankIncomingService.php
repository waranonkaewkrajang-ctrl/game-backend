<?php

namespace App\Services;

use App\Models\BankIncomingTx;
use App\Models\Deposit;
use App\Models\Setting;
use App\Models\UnmatchedDeposit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ด่านตรวจเงินเข้า (bot อ่าน LINE ธนาคาร → ส่งมาที่นี่ → เซิร์ฟเวอร์ตัดสินใจเองทั้งหมด)
 *
 * ด่าน 1  สมุดบัญชีเงินเข้า: fingerprint unique → ข้อความเดิมส่งมากี่รอบก็ทำงานครั้งเดียว
 * ด่าน 2  ช่วงอ่านย้อนหลัง: mode=backlog หรือเวลาโอนเก่าเกินกำหนด → ไม่ auto ส่งให้คนตรวจ
 * ด่าน 3  ผูก 1 เงินเข้า = 1 รายการฝาก (unique deposit_id) + lock แถวใน transaction
 * ด่าน 4  เบรกฉุกเฉิน: auto ถี่ผิดปกติ → หยุด auto ชั่วคราว + แจ้ง Telegram
 * กฎจับคู่: ยอดตรง + เวลาโอนอยู่ในกรอบเวลาของรายการฝาก + เลขบัญชี 4 ตัวท้ายตรงกับบัญชีที่ลูกค้าลงทะเบียน
 *
 * ทุกค่าอ่านจาก settings (แก้ได้ในหลังบ้าน) — ค่าใน DEFAULTS ใช้เฉพาะตอนยังไม่ได้ตั้ง
 */
class BankIncomingService
{
    public const DEFAULTS = [
        'bank_auto_approve_enabled'  => 'true', // เปิด/ปิด อนุมัติอัตโนมัติทั้งระบบ
        'bank_require_account_match' => 'true', // ต้องเลขบัญชี 4 ตัวท้ายตรงเท่านั้นถึง auto
        'bank_match_before_min'      => '15',   // โอนก่อนกดแจ้งฝากได้ไม่เกินกี่นาที
        'bank_match_after_min'       => '60',   // โอนหลังกดแจ้งฝากได้ไม่เกินกี่นาที
        'bank_max_tx_age_min'        => '30',   // เวลาโอนเก่ากว่านี้ (นาที) = ไม่ auto
        'bank_burst_max'             => '4',    // auto ได้สูงสุดกี่รายการ ต่อช่วงเวลาด้านล่าง
        'bank_burst_window_sec'      => '60',
        'bank_burst_pause_min'       => '30',   // เกินแล้วหยุด auto กี่นาที
        'bank_auto_paused_until'     => '',     // ระบบตั้งเองตอนเบรก (ล้างได้ด้วยปุ่ม resume)
        'bank_ingest_since'          => '',     // วันเวลาเริ่มใช้ระบบนี้ — ข้อความที่โอนก่อนนี้ไม่ทำอะไร
    ];

    public const REASONS = [
        'auto_disabled'         => 'ปิดอนุมัติอัตโนมัติอยู่ (ตั้งค่า)',
        'backlog'               => 'ข้อความเข้ามาช่วง bot เพิ่งเปิด/เชื่อม LINE ใหม่',
        'bad_time'              => 'อ่านเวลาโอนไม่ได้',
        'too_old'               => 'เวลาโอนเก่าเกินกำหนด',
        'future_time'           => 'เวลาโอนอยู่ในอนาคต',
        'paused'                => 'ระบบหยุด auto ชั่วคราว (เบรกฉุกเฉิน)',
        'burst'                 => 'อนุมัติอัตโนมัติถี่ผิดปกติ — สั่งหยุด auto',
        'no_account_in_message' => 'ข้อความไม่มีเลขบัญชีผู้โอน',
        'account_mismatch'      => 'เลขบัญชีผู้โอนไม่ตรงกับบัญชีที่ลงทะเบียน',
        'multiple_users'        => 'มีลูกค้ามากกว่า 1 คนที่ยอด/เวลา/บัญชีตรง',
        'no_pending_deposit'    => 'ไม่มีรายการแจ้งฝากที่ตรง',
    ];

    public function __construct(
        private DepositService $depositService,
    ) {}

    // ------------------------------------------------------------------
    //  entry point
    // ------------------------------------------------------------------

    /**
     * @param array{bank?:string,amount:mixed,tx_time?:string,from?:string,from_account_4?:string,source?:string,mode?:string} $input
     * @return array{result:string,incoming_id:int,status:string,reason:?string,message:string,deposit_id:?int,unmatched_id:?int,username:?string}
     */
    public function ingest(array $input, ?int $adminId): array
    {
        $n   = $this->normalize($input);
        $now = now();

        $after = []; // งานที่ต้องทำหลัง commit (Telegram / broadcast)

        $out = DB::transaction(function () use ($n, $now, $adminId, &$after) {
            $inserted = BankIncomingTx::query()->insertOrIgnore([
                'fingerprint'    => $n['fingerprint'],
                'bank'           => $n['bank'],
                'amount'         => $n['amount'],
                'tx_time_raw'    => $n['tx_time_raw'],
                'tx_at'          => $n['tx_at']?->format('Y-m-d H:i:s'),
                'from_account'   => $n['from'],
                'from_account_4' => $n['acc4'] !== '' ? $n['acc4'] : null,
                'source'         => $n['source'],
                'mode'           => $n['mode'],
                'status'         => 'received',
                'reported_by'    => $adminId,
                'seen_count'     => 1,
                'last_seen_at'   => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            $tx = BankIncomingTx::where('fingerprint', $n['fingerprint'])->lockForUpdate()->firstOrFail();

            // ด่าน 1 — เคยเห็นแล้ว: นับจำนวนครั้งเฉยๆ ไม่ทำอะไรกับเงิน
            if ($inserted === 0) {
                $tx->update(['seen_count' => $tx->seen_count + 1, 'last_seen_at' => $now]);
                return $this->result('duplicate', $tx, 'เคยรับข้อความนี้แล้ว (ครั้งที่ ' . $tx->seen_count . ') — ข้าม');
            }

            return $this->process($tx, $adminId, $after);
        });

        foreach ($after as $job) {
            try { $job(); } catch (\Throwable $e) { Log::warning('bank-incoming after-commit failed', ['error' => $e->getMessage()]); }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    //  decision
    // ------------------------------------------------------------------

    private function process(BankIncomingTx $tx, ?int $adminId, array &$after): array
    {
        $cfg = $this->config();

        // ก่อนวันเริ่มใช้ระบบ → ระบบเดิมจัดการไปแล้ว ไม่แตะ
        if ($cfg['since'] && $tx->tx_at && $tx->tx_at->lt($cfg['since'])) {
            $this->finish($tx, 'before_cutover', null, 'โอนก่อนเริ่มใช้ระบบกันซ้ำ — ไม่ดำเนินการ');
            return $this->result('ignored', $tx, 'ข้อความก่อนวันเริ่มใช้ระบบ — ไม่ดำเนินการ');
        }

        $block = $this->blockReason($tx, $cfg);

        $cands = $tx->tx_at ? $this->candidates($tx, $cfg) : collect();
        $acc4  = (string) $tx->from_account_4;
        $byAcc = $acc4 !== '' ? $cands->filter(fn (Deposit $d) => $this->last4($d->user?->bank_account) === $acc4)->values() : collect();
        $pool  = $cfg['require_account'] ? $byAcc : ($byAcc->isNotEmpty() ? $byAcc : $cands);
        $users = $pool->pluck('user_id')->unique();

        $also = $block ? ' + ' . self::REASONS[$block] : ''; // เหตุผลเสริม ให้แอดมินเห็นครบ

        if ($pool->isEmpty()) {
            if ($cands->isNotEmpty()) {
                $reason = $acc4 === '' ? 'no_account_in_message' : 'account_mismatch';
                return $this->toUnmatched($tx, 'review', $reason, $also . ' — ' . $this->suggest($cands, $reason), $after);
            }
            return $this->toUnmatched($tx, 'unmatched', 'no_pending_deposit', $also ?: null, $after);
        }

        if ($users->count() > 1) {
            return $this->toUnmatched($tx, 'review', 'multiple_users', $also . ' — ' . $this->suggest($pool, 'multiple_users'), $after);
        }

        $deposit = $pool->sortBy('id')->first(); // คนเดียวกดซ้ำหลายรายการ → เงิน 1 ก้อน ปิดได้แค่รายการเก่าสุด

        if ($block) {
            return $this->toUnmatched($tx, 'review', $block, ' — ' . $this->suggest(collect([$deposit]), $block), $after);
        }

        // ด่าน 4 — เบรกฉุกเฉิน (นับเฉพาะ auto ที่สำเร็จในช่วงเวลา)
        $recent = BankIncomingTx::where('status', 'auto_approved')
            ->where('processed_at', '>=', now()->subSeconds($cfg['burst_window']))
            ->count();
        if ($recent >= $cfg['burst_max']) {
            $until = now()->addMinutes($cfg['burst_pause']);
            Setting::setValue('bank_auto_paused_until', $until->format('Y-m-d H:i:s'));
            $after[] = fn () => app(TelegramService::class)->send(
                "🛑 <b>หยุดอนุมัติฝากอัตโนมัติชั่วคราว</b>\n" .
                "อนุมัติ auto {$recent} รายการใน {$cfg['burst_window']} วินาที (ผิดปกติ)\n" .
                "หยุดถึง {$until->format('H:i')} — รายการระหว่างนี้ไปที่ยอดค้างให้แอดมินตรวจ"
            );
            return $this->toUnmatched($tx, 'review', 'burst', ' — ' . $this->suggest(collect([$deposit]), 'burst'), $after);
        }

        return $this->autoApprove($tx, $deposit, $adminId, $after);
    }

    private function blockReason(BankIncomingTx $tx, array $cfg): ?string
    {
        if (!$cfg['enabled'])      return 'auto_disabled';
        if ($tx->mode === 'backlog') return 'backlog';
        if (!$tx->tx_at)           return 'bad_time';
        if ($tx->tx_at->lt(now()->subMinutes($cfg['max_age']))) return 'too_old';
        if ($tx->tx_at->gt(now()->addMinutes(5))) return 'future_time';
        if ($cfg['paused_until'] && now()->lt($cfg['paused_until'])) return 'paused';
        return null;
    }

    /** รายการแจ้งฝากที่ยัง pending + ยอดตรง + เวลาแจ้งฝากอยู่ในกรอบ + ยังไม่เคยถูกผูกกับเงินเข้าไหน */
    private function candidates(BankIncomingTx $tx, array $cfg): Collection
    {
        $amount = (float) $tx->amount;
        // เวลาธนาคารละเอียดแค่นาที → เผื่อ 1 นาทีด้านบน
        $from = $tx->tx_at->copy()->subMinutes($cfg['after']);
        $to   = $tx->tx_at->copy()->addMinutes($cfg['before'] + 1);

        return Deposit::with('user')
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', '!=', 'truewallet'))
            ->whereBetween('amount', [$amount - 0.001, $amount + 0.001])
            ->whereBetween('created_at', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('bank_incoming_txs as bi')->whereColumn('bi.deposit_id', 'deposits.id');
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    // ------------------------------------------------------------------
    //  actions
    // ------------------------------------------------------------------

    private function autoApprove(BankIncomingTx $tx, Deposit $deposit, ?int $adminId, array &$after): array
    {
        // ผูกก่อน (unique deposit_id) — ถ้ามีเงินเข้าอีกก้อนแย่งรายการเดียวกัน DB จะปฏิเสธ
        $tx->update(['deposit_id' => $deposit->id]);
        $deposit->update(['approved_method' => 'auto']);

        $approved = $this->depositService->approve($deposit, $adminId); // lock + เช็ค pending + เติมเงิน (ของเดิม)
        $username = $approved->user?->username;

        $this->finish($tx, 'auto_approved', null, "อนุมัติ #{$approved->id} ให้ {$username}");

        $after[] = fn () => broadcast(new \App\Events\DepositApproved($approved->fresh()));
        $after[] = fn () => app(TelegramService::class)->notifyDeposit((string) $username, (float) $approved->amount);

        return $this->result('auto_approved', $tx, "อนุมัติ #{$approved->id} | {$username} | {$approved->amount} บาท", $approved->id, null, $username);
    }

    /** $detail = ข้อความต่อท้าย (เหตุผลเสริม / รายการที่ใกล้เคียง) */
    private function toUnmatched(BankIncomingTx $tx, string $status, string $reason, ?string $detail, array &$after): array
    {
        $label = self::REASONS[$reason] ?? $reason;
        $note  = ($status === 'review' ? '⚠️ รอตรวจ: ' : '') . $label . ($detail ?? '');

        $um = UnmatchedDeposit::create([
            'bank'         => $tx->bank,
            'amount'       => $tx->amount,
            'from_account' => $tx->from_account,
            'tx_time'      => $tx->tx_time_raw,
            'note'         => mb_substr($note, 0, 250),
        ]);
        $tx->update(['unmatched_deposit_id' => $um->id]);
        $this->finish($tx, $status, $reason, $note);

        $after[] = function () {
            event(new \App\Events\AdminBadgeUpdated('unmatched', UnmatchedDeposit::where('status', 'pending')->count()));
        };
        $after[] = fn () => app(TelegramService::class)->send(
            ($status === 'review' ? "⚠️ <b>เงินเข้า รอแอดมินตรวจ</b>\n" : "⚠️ <b>เงินเข้าไม่มีคนแจ้งฝาก</b>\n") .
            "💵 จำนวน: ฿" . number_format((float) $tx->amount, 2) . "\n" .
            "🏦 ธนาคาร: {$tx->bank}\n" .
            "👤 จาก: {$tx->from_account}\n" .
            "📅 เวลา: {$tx->tx_time_raw}\n" .
            "📝 {$label}"
        );

        return $this->result($status, $tx, $note, null, $um->id);
    }

    private function finish(BankIncomingTx $tx, string $status, ?string $reason, ?string $note): void
    {
        $tx->update(['status' => $status, 'reason' => $reason, 'note' => $note, 'processed_at' => now()]);
    }

    private function suggest(Collection $deposits, string $reason): string
    {
        $parts = $deposits->take(5)->map(fn (Deposit $d) => '#' . $d->id . ' ' . ($d->user?->username ?? '?'))->implode(', ');
        $tail  = in_array($reason, ['account_mismatch', 'no_account_in_message', 'multiple_users'], true)
            ? 'ตรวจว่าเป็นเงินของใคร'
            : 'ถ้าถูกต้อง อนุมัติยอดค้างนี้ให้ลูกค้า แล้วปฏิเสธรายการแจ้งฝากเดิม';
        return "รายการแจ้งฝากที่ใกล้เคียง: {$parts} ({$tail})";
    }

    private function result(string $result, BankIncomingTx $tx, string $message, ?int $depositId = null, ?int $unmatchedId = null, ?string $username = null): array
    {
        return [
            'result'       => $result,
            'incoming_id'  => $tx->id,
            'status'       => $tx->status,
            'reason'       => $tx->reason,
            'message'      => $message,
            'deposit_id'   => $depositId ?? $tx->deposit_id,
            'unmatched_id' => $unmatchedId ?? $tx->unmatched_deposit_id,
            'username'     => $username,
        ];
    }

    // ------------------------------------------------------------------
    //  normalise / config
    // ------------------------------------------------------------------

    public function normalize(array $in): array
    {
        $from   = trim(preg_replace('/\s+/u', ' ', (string) ($in['from'] ?? '')));
        $amount = number_format(round((float) str_replace(',', '', (string) ($in['amount'] ?? 0)), 2), 2, '.', '');
        $acc4   = preg_replace('/\D/', '', (string) ($in['from_account_4'] ?? ''));
        if (strlen($acc4) !== 4) {
            $acc4 = preg_match('/[Xx][-\s]?(\d{4})/u', $from, $m) ? $m[1] : '';
        }
        $raw  = trim((string) ($in['tx_time'] ?? ''));
        $txAt = self::parseTxTime($raw);
        $mode = ($in['mode'] ?? 'live') === 'backlog' ? 'backlog' : 'live';
        $src  = in_array($in['source'] ?? null, ['scb_json', 'text'], true) ? $in['source'] : null;

        // ไม่ใส่ธนาคาร/แหล่งที่อ่าน ใน fingerprint — ข้อความเดียวกันที่อ่านได้ 2 ทาง (JSON / ข้อความ) ต้องได้ค่าเดียวกัน
        $who = $acc4 !== '' ? 'a:' . $acc4 : 'f:' . mb_strtoupper($from);
        $when = $txAt ? $txAt->format('Y-m-d H:i') : 'raw:' . $raw;
        $fingerprint = hash('sha256', implode('|', [$amount, $when, $who]));

        return [
            'fingerprint' => $fingerprint,
            'bank'        => mb_substr(trim((string) ($in['bank'] ?? '')) ?: 'OTHER', 0, 50),
            'amount'      => $amount,
            'tx_time_raw' => $raw !== '' ? mb_substr($raw, 0, 100) : null,
            'tx_at'       => $txAt,
            'from'        => $from !== '' ? mb_substr($from, 0, 255) : null,
            'acc4'        => $acc4,
            'source'      => $src,
            'mode'        => $mode,
        ];
    }

    /** "13/11/2026 13:42" / "13/11/69 13:42" / "13/11/2569 13:42" → Carbon (เวลาไทยตาม app.timezone) */
    public static function parseTxTime(string $raw): ?Carbon
    {
        if (!preg_match('#(\d{1,2})/(\d{1,2})/(\d{2,4})\s+(\d{1,2}):(\d{2})#', $raw, $m)) {
            return null;
        }
        [$d, $mo, $y, $h, $i] = array_map('intval', array_slice($m, 1));
        if ($y < 100) {
            $y += $y >= 43 ? 2500 - 543 : 2000; // 2 หลัก: 69 = พ.ศ.2569, 26 = ค.ศ.2026
        } elseif ($y > 2400) {
            $y -= 543;
        }
        if (!checkdate($mo, $d, $y) || $h > 23 || $i > 59) {
            return null;
        }
        return Carbon::create($y, $mo, $d, $h, $i, 0, config('app.timezone'));
    }

    private function last4(?string $account): string
    {
        $digits = preg_replace('/\D/', '', (string) $account);
        return strlen($digits) >= 4 ? substr($digits, -4) : '';
    }

    public function config(): array
    {
        $raw = Setting::whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key')->all() + self::DEFAULTS;
        $bool = fn ($v) => in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
        $int  = fn ($v, int $min, int $max) => max($min, min($max, (int) $v));
        $date = function ($v) {
            $v = trim((string) $v);
            if ($v === '') return null;
            try { return Carbon::parse($v, config('app.timezone')); } catch (\Throwable) { return null; }
        };

        return [
            'enabled'         => $bool($raw['bank_auto_approve_enabled']),
            'require_account' => $bool($raw['bank_require_account_match']),
            'before'          => $int($raw['bank_match_before_min'], 0, 1440),
            'after'           => $int($raw['bank_match_after_min'], 1, 1440),
            'max_age'         => $int($raw['bank_max_tx_age_min'], 1, 1440),
            'burst_max'       => $int($raw['bank_burst_max'], 1, 1000),
            'burst_window'    => $int($raw['bank_burst_window_sec'], 5, 3600),
            'burst_pause'     => $int($raw['bank_burst_pause_min'], 1, 1440),
            'paused_until'    => $date($raw['bank_auto_paused_until']),
            'since'           => $date($raw['bank_ingest_since']),
        ];
    }
}
