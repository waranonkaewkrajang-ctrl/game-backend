<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Housekeeping for the unified-admin idempotency ledger (internal_admin_operations).
 * Deletes only FINISHED records older than INTERNAL_ADMIN_IDEMPOTENCY_KEEP_DAYS (default 30).
 * Records still "processing" are never deleted automatically (their real outcome is unknown) — they are
 * only reported so an admin can check the related deposit/withdrawal by hand.
 */
class InternalAdminPrune extends Command
{
    protected $signature = 'internal-admin:prune';

    protected $description = 'Delete old finished idempotency records of the unified admin; report stuck ones';

    public function handle(): int
    {
        if (! Schema::hasTable('internal_admin_operations')) {
            $this->info('table missing — nothing to do');
            return self::SUCCESS;
        }
        $days = max(1, (int) config('services.internal_admin.idempotency_keep_days', 30));
        $deleted = DB::table('internal_admin_operations')
            ->where('status', 'done')
            ->where('updated_at', '<', now()->subDays($days))
            ->delete();
        $stuck = DB::table('internal_admin_operations')
            ->where('status', 'processing')
            ->where('created_at', '<', now()->subMinutes(10))
            ->count();
        if ($stuck > 0) {
            $this->warn("processing_over_10min={$stuck} (check these actions by hand — not deleted)");
        }
        $this->info("deleted={$deleted} stuck={$stuck}");

        return self::SUCCESS;
    }
}
