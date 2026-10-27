<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * สรุป log ตรวจ IP ของ callback ค่ายเกม และบอกว่าพร้อมเปิด enforce หรือยัง
 * อ่านไฟล์ storage/logs/amb-callback-ip-*.log อย่างเดียว ไม่แตะฐานข้อมูล
 */
class AmbCallbackIpReport extends Command
{
    protected $signature = 'amb:callback-ip-report {--days=7 : จำนวนวันย้อนหลังที่ต้องมี log}';
    protected $description = 'สรุป IP ที่ยิง callback ค่ายเกม (ผ่าน/ไม่ผ่าน) และเช็คว่าพร้อมเปิด enforce หรือยัง';

    public function handle(): int
    {
        $days      = max(1, (int) $this->option('days'));
        $whitelist = (array) config('amb.callback_ips', []);
        $proxies   = (array) config('amb.trusted_proxies', []);
        $mode      = config('amb.callback_ip_mode', 'monitor');

        // ── อ่าน log ──
        $stats    = [];
        $dates    = [];
        $spoofed  = 0;
        $from     = now()->subDays($days - 1)->toDateString();

        foreach (glob(storage_path('logs/amb-callback-ip-*.log')) ?: [] as $file) {
            if (!preg_match('/(\d{4}-\d{2}-\d{2})\.log$/', $file, $m) || $m[1] < $from) {
                continue;
            }
            $dates[$m[1]] = true;

            foreach (new \SplFileObject($file) as $line) {
                $row = json_decode((string) $line, true);
                if (!is_array($row) || !in_array($row['message'] ?? '', ['PASS', 'FAIL', 'BLOCK'], true)) {
                    continue;
                }

                $ctx = $row['context'] ?? [];
                $ip  = $ctx['client_ip'] ?? '(ไม่ทราบ)';

                $stats[$ip] ??= ['PASS' => 0, 'FAIL' => 0, 'BLOCK' => 0, 'last' => ''];
                $stats[$ip][$row['message']]++;
                $stats[$ip]['last'] = max($stats[$ip]['last'], substr((string) ($row['datetime'] ?? ''), 0, 19));

                if (($ctx['laravel_ip'] ?? null) !== ($ctx['client_ip'] ?? null)) {
                    $spoofed++;
                }
            }
        }

        // ── ตาราง ──
        uasort($stats, fn ($a, $b) => ($b['PASS'] + $b['FAIL'] + $b['BLOCK']) <=> ($a['PASS'] + $a['FAIL'] + $a['BLOCK']));
        $this->table(
            ['IP', 'PASS', 'FAIL', 'BLOCK', 'ล่าสุด', 'ใน whitelist'],
            collect($stats)->map(fn ($s, $ip) => [
                $ip, number_format($s['PASS']), number_format($s['FAIL']), number_format($s['BLOCK']),
                str_replace('T', ' ', $s['last']),
                $whitelist && filter_var($ip, FILTER_VALIDATE_IP) && IpUtils::checkIp($ip, $whitelist) ? 'ใช่' : '-',
            ])->values()->all()
        );

        $this->line("โหมดปัจจุบัน: {$mode} | whitelist: " . count($whitelist) . ' รายการ | trusted proxies: ' . implode(', ', $proxies));
        $this->line('มี log ' . count($dates) . "/{$days} วัน" . ($spoofed ? " | X-Forwarded-For ไม่ตรงกับ IP จริง {$spoofed} ครั้ง (มีคนพยายามปลอม IP)" : ''));

        // ── เช็คความพร้อม ──
        $problems = [];
        $warnings = [];

        if ($whitelist === []) {
            $problems[] = 'ยังไม่ได้ตั้ง AMB_CALLBACK_IPS';
        }
        foreach ($whitelist as $entry) {
            if (!filter_var(explode('/', $entry)[0], FILTER_VALIDATE_IP)) {
                $problems[] = "AMB_CALLBACK_IPS มีค่าที่ไม่ใช่ IP: {$entry}";
            }
        }
        if (count($dates) < $days) {
            $problems[] = 'log ยังไม่ครบ ' . $days . ' วัน (มี ' . count($dates) . ' วัน)';
        }
        foreach ($whitelist as $entry) {
            $seen = collect($stats)->contains(fn ($s, $ip) => $s['PASS'] > 0 && filter_var($ip, FILTER_VALIDATE_IP) && IpUtils::checkIp($ip, $entry));
            if (!$seen) {
                $problems[] = "IP ใน whitelist ยังไม่เคยมี PASS: {$entry}";
            }
        }
        foreach ($stats as $ip => $s) {
            if ($s['FAIL'] + $s['BLOCK'] === 0) {
                continue;
            }
            if ($ip === '(ไม่ทราบ)' || (filter_var($ip, FILTER_VALIDATE_IP) && $proxies && IpUtils::checkIp($ip, $proxies))) {
                $problems[] = "มี FAIL ที่ IP เป็น {$ip} — อ่าน IP จริงไม่ได้ ตรวจ AMB_TRUSTED_PROXIES / proxy_set_header X-Forwarded-For ของ nginx";
            } else {
                $warnings[] = "{$ip} ไม่ผ่าน " . ($s['FAIL'] + $s['BLOCK']) . ' ครั้ง — ยืนยันว่าไม่ใช่ AMB ก่อน (ถ้าเป็น AMB ต้องเพิ่มเข้า whitelist)';
            }
        }

        foreach ($warnings as $w) {
            $this->warn("⚠ {$w}");
        }

        if ($problems) {
            foreach ($problems as $p) {
                $this->error("✗ {$p}");
            }
            $this->error('ผล: ❌ ยังไม่พร้อมเปิด enforce');
            return self::FAILURE;
        }

        $this->info($warnings ? 'ผล: ✅ พร้อมเปิด enforce (หลังตรวจรายการ ⚠ ข้างบนแล้ว)' : 'ผล: ✅ พร้อมเปิด enforce');

        return self::SUCCESS;
    }
}
