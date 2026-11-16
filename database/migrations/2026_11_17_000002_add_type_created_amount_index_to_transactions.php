<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard / profit report sum bets, wins and bonuses by type + time range:
 *   SELECT SUM(amount) FROM transactions WHERE type = ? AND created_at BETWEEN ? AND ? [GROUP BY day]
 * Without an index this scanned all ~2M rows (1.1–2.5 s per query, 6–8 queries per page).
 * (type, created_at, amount) answers them from the index alone ("Using index").
 * Measured on a full copy of production: same results, 1.1–1.7 s → 13–160 ms, index ≈ 77 MB, build ≈ 10 s.
 *
 * Transactions list (ORDER BY created_at DESC LIMIT 20, no type filter) sorted all ~2M joined rows: 26.7 s.
 * (created_at) lets MySQL read the newest rows backwards from the index: 16 ms (build ≈ 9 s).
 *
 * MySQL: online (ALGORITHM=INPLACE, LOCK=NONE) — inserts from game callbacks continue during the build.
 * lock_wait_timeout=5: if a long transaction holds the table, the migration fails fast instead of queueing
 * everything behind its metadata lock. Additive only; down() drops just this index.
 */
return new class extends Migration
{
    private const INDEXES = [
        'tx_type_created_amount' => ['type', 'created_at', 'amount'],
        'tx_created_at'          => ['created_at'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $cols) {
            if ($this->exists($name)) {
                continue;
            }
            if (DB::getDriverName() === 'mysql') {
                DB::statement('SET SESSION lock_wait_timeout = 5');
                DB::statement("ALTER TABLE transactions ADD INDEX {$name} (".implode(', ', $cols).'), ALGORITHM=INPLACE, LOCK=NONE');
                continue;
            }
            Schema::table('transactions', fn ($t) => $t->index($cols, $name));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            if ($this->exists($name)) {
                Schema::table('transactions', fn ($t) => $t->dropIndex($name));
            }
        }
    }

    private function exists(string $name): bool
    {
        return collect(Schema::getIndexes('transactions'))->contains(fn ($i) => $i['name'] === $name);
    }
};
