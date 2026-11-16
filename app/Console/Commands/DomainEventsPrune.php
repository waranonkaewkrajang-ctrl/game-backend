<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** ลบเฉพาะ event ที่ส่งสำเร็จแล้ว (delivered) และเก่ากว่า retention_days — ที่ค้าง/dead ไม่ลบ + เตือนถ้าค้างนาน */
class DomainEventsPrune extends Command
{
    protected $signature = 'domain-events:prune';
    protected $description = 'Delete delivered outbox events older than the retention period; warn about old undelivered events';

    public function handle(): int
    {
        $days = max(1, (int) config('domain_events.retention_days', 14));
        $deleted = 0;
        do {
            $n = DB::table('domain_outbox_events')
                ->where('status', 'delivered')
                ->where('delivered_at', '<', now()->subDays($days))
                ->limit(1000)
                ->delete();
            $deleted += $n;
        } while ($n === 1000);

        $alertMin = max(1, (int) config('domain_events.alert_after_minutes', 10));
        $stuck = DB::table('domain_outbox_events')
            ->whereIn('status', ['pending', 'failed', 'dead'])
            ->where('created_at', '<', now()->subMinutes($alertMin))
            ->count();
        if ($stuck > 0) {
            Log::warning("[DomainOutbox] {$stuck} event(s) undelivered for more than {$alertMin} minutes");
        }
        $this->info("deleted={$deleted} undelivered_old={$stuck}");
        return self::SUCCESS;
    }
}
