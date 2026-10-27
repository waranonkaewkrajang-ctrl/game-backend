<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * สรุป log ตรวจลายเซ็น TrueWallet webhook และบอกว่าพร้อมเปิด enforce หรือยัง
 * อ่านไฟล์ storage/logs/truewallet-webhook-*.log อย่างเดียว ไม่แตะฐานข้อมูล
 */
class TrueWalletWebhookReport extends Command
{
    protected $signature = 'truewallet:webhook-report {--days=7 : จำนวนวันย้อนหลังที่ต้องมี log}';
    protected $description = 'สรุป TrueWallet webhook (มีลายเซ็น/ไม่มี) แยกตาม IP และเช็คว่าพร้อมเปิด enforce หรือยัง';

    private const RESULTS = ['VERIFIED', 'UNSIGNED', 'BAD_SIGNATURE', 'NO_SECRET', 'PING'];

    public function handle(): int
    {
        $days  = max(1, (int) $this->option('days'));
        $from  = now()->subDays($days - 1)->toDateString();
        $stats = [];
        $dates = [];
        $formats    = [];
        $suspicious = [];

        foreach (glob(storage_path('logs/truewallet-webhook-*.log')) ?: [] as $file) {
            if (!preg_match('/(\d{4}-\d{2}-\d{2})\.log$/', $file, $m) || $m[1] < $from) {
                continue;
            }
            $dates[$m[1]] = true;

            foreach (new \SplFileObject($file) as $line) {
                $row    = json_decode((string) $line, true);
                $result = $row['message'] ?? null;
                if (!is_array($row) || !in_array($result, self::RESULTS, true)) {
                    continue;
                }

                $ctx = $row['context'] ?? [];
                $ip  = $ctx['client_ip'] ?? '(ไม่ทราบ)';

                $stats[$ip] ??= array_fill_keys(self::RESULTS, 0) + ['BLOCK' => 0, 'last' => ''];
                $stats[$ip][$result]++;
                $stats[$ip]['last'] = max($stats[$ip]['last'], substr((string) ($row['datetime'] ?? ''), 0, 19));
                if (($ctx['action'] ?? null) === 'block') {
                    $stats[$ip]['BLOCK']++;
                }

                $formats[$result][$ctx['format'] ?? '-'] = ($formats[$result][$ctx['format'] ?? '-'] ?? 0) + 1;

                if (in_array($result, ['UNSIGNED', 'BAD_SIGNATURE'], true) && ($ctx['amount'] ?? 0) > 0) {
                    $suspicious[] = [$ip, $ctx['transaction_id'] ?? '-', $ctx['amount'], $ctx['event_type'] ?? '-', $ctx['action'] ?? '-', str_replace('T', ' ', substr((string) ($row['datetime'] ?? ''), 0, 19))];
                }
            }
        }

        $this->table(
            ['IP', 'VERIFIED', 'UNSIGNED', 'BAD_SIG', 'NO_SECRET', 'PING', 'BLOCK', 'ล่าสุด'],
            collect($stats)->map(fn ($s, $ip) => [
                $ip, $s['VERIFIED'], $s['UNSIGNED'], $s['BAD_SIGNATURE'], $s['NO_SECRET'], $s['PING'], $s['BLOCK'], str_replace('T', ' ', $s['last']),
            ])->values()->all()
        );

        foreach ($formats as $result => $f) {
            $this->line("รูปแบบ body ของ {$result}: " . collect($f)->map(fn ($n, $k) => "{$k}={$n}")->implode(', '));
        }
        $this->line('โหมดปัจจุบัน: ' . config('truewallet.webhook_mode') . ' | secret: ' . (config('truewallet.webhook_secret') ? 'ตั้งแล้ว' : 'ยังไม่ตั้ง') . ' | มี log ' . count($dates) . "/{$days} วัน");

        // ── เช็คความพร้อม ──
        $problems = [];
        $warnings = [];
        $verifiedIps = collect($stats)->filter(fn ($s) => $s['VERIFIED'] > 0)->keys()->all();

        if (!config('truewallet.webhook_secret')) {
            $problems[] = 'ยังไม่ได้ตั้ง TRUEWALLET_WEBHOOK_SECRET';
        }
        if (count($dates) < $days) {
            $problems[] = "log ยังไม่ครบ {$days} วัน (มี " . count($dates) . ' วัน)';
        }
        if ($verifiedIps === []) {
            $problems[] = 'ยังไม่มี VERIFIED เลย — ยังพิสูจน์ไม่ได้ว่าฝากจริงมีลายเซ็น';
        }
        if (collect($stats)->sum('NO_SECRET') > 0) {
            $problems[] = 'มีช่วงที่ระบบอ่าน secret ไม่ได้ (NO_SECRET)';
        }
        foreach ($suspicious as [$ip, $txn, $amount]) {
            if (in_array($ip, $verifiedIps, true)) {
                $problems[] = "IP {$ip} เคยส่งแบบมีลายเซ็น แต่รายการ {$txn} ({$amount}) ไม่มีลายเซ็นถูกต้อง — ฝากจริงอาจไหลผ่าน fallback ถ้าเปิด enforce จะหยุด";
            }
        }

        if ($suspicious) {
            $this->warn('รายการมียอดเงินแต่ไม่มีลายเซ็นถูกต้อง (ล่าสุด 20 รายการ):');
            $this->table(['IP', 'transaction_id', 'amount (สตางค์)', 'event', 'action', 'เวลา'], array_slice($suspicious, -20));
            $others = collect($suspicious)->reject(fn ($r) => in_array($r[0], $verifiedIps, true))->count();
            if ($others) {
                $warnings[] = "มี {$others} รายการจาก IP ที่ไม่เคยส่งลายเซ็นถูกต้อง — น่าจะเป็นการปลอม ตรวจ deposits ที่ reference_id = 'TW-<transaction_id>'";
            }
        }

        foreach ($warnings as $w) {
            $this->warn("⚠ {$w}");
        }

        if ($problems) {
            foreach (array_unique($problems) as $p) {
                $this->error("✗ {$p}");
            }
            $this->error('ผล: ❌ ยังไม่พร้อมเปิด enforce');
            return self::FAILURE;
        }

        $this->info($warnings ? 'ผล: ✅ พร้อมเปิด enforce (หลังตรวจรายการ ⚠ ข้างบนแล้ว)' : 'ผล: ✅ พร้อมเปิด enforce');

        return self::SUCCESS;
    }
}
