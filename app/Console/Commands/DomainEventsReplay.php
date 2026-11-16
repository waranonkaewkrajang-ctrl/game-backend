<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** ส่ง event ที่ dead/failed ใหม่ (ปลายทางเป็น idempotent ด้วย event_id — ส่งซ้ำไม่เกิดงานซ้ำ) */
class DomainEventsReplay extends Command
{
    protected $signature = 'domain-events:replay {--status=dead : dead|failed} {--id=* : specific outbox ids} {--dry-run}';
    protected $description = 'Re-queue dead/failed outbox events';

    public function handle(): int
    {
        $status = (string) $this->option('status');
        if (!in_array($status, ['dead', 'failed'], true)) {
            $this->error('status ต้องเป็น dead หรือ failed');
            return self::INVALID;
        }
        $q = DB::table('domain_outbox_events')->where('status', $status);
        if ($ids = array_filter(array_map('intval', (array) $this->option('id')))) {
            $q->whereIn('id', $ids);
        }
        $count = (clone $q)->count();
        if ($this->option('dry-run')) {
            $this->info("จะส่งใหม่ {$count} รายการ");
            return self::SUCCESS;
        }
        $q->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'locked_until' => null, 'lock_token' => null, 'updated_at' => now()]);
        $this->info("ตั้งให้ส่งใหม่ {$count} รายการ");
        return self::SUCCESS;
    }
}
