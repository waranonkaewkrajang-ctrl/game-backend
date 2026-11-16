<?php

namespace App\Console\Commands;

use App\Domain\Events\OutboxDispatcher;
use Illuminate\Console\Command;

/**
 * ส่ง outbox ที่ค้าง (สแกนตารางในเครื่อง — ไม่ใช่การ pull ข้ามเซิร์ฟเวอร์)
 * - schedule เรียกทุกนาทีเป็นตัวกู้คืน (กรณี wake หลัง commit ไม่ได้ทำงาน / ปลายทางเคยล่ม)
 * - --loop: ใช้เป็น worker ถาวร (pm2/supervisor) หลับ --sleep วินาทีเมื่อไม่มีงาน
 */
class DomainEventsDispatch extends Command
{
    protected $signature = 'domain-events:dispatch {--loop : run continuously} {--sleep=2 : seconds to sleep when idle (loop)} {--max-batches=20}';
    protected $description = 'Push pending domain events from the outbox to admin-api';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $sleep = max(1, (int) $this->option('sleep'));
        do {
            $r = $dispatcher->drain(max(1, (int) $this->option('max-batches')));
            if ($r['claimed'] || isset($r['skipped'])) {
                $this->line(json_encode($r));
            }
            if (isset($r['skipped']) && !$this->option('loop')) {
                return self::SUCCESS;
            }
            if ($this->option('loop')) {
                sleep($r['delivered'] > 0 ? 0 : $sleep);
            }
        } while ($this->option('loop'));

        return self::SUCCESS;
    }
}
