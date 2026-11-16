<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Betting history page: SELECT * FROM game_logs ORDER BY created_at DESC LIMIT 50 sorted all ~3.3M rows (≈7 s).
 * (created_at) lets MySQL read the newest rows backwards from the index. Additive, online
 * (ALGORITHM=INPLACE, LOCK=NONE — game callbacks keep writing), fails fast on a metadata lock.
 */
return new class extends Migration
{
    private const NAME = 'gl_created_at';

    public function up(): void
    {
        if ($this->exists()) {
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET SESSION lock_wait_timeout = 5');
            DB::statement('ALTER TABLE game_logs ADD INDEX '.self::NAME.' (created_at), ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }
        Schema::table('game_logs', fn ($t) => $t->index(['created_at'], self::NAME));
    }

    public function down(): void
    {
        if ($this->exists()) {
            Schema::table('game_logs', fn ($t) => $t->dropIndex(self::NAME));
        }
    }

    private function exists(): bool
    {
        return Schema::hasTable('game_logs')
            && collect(Schema::getIndexes('game_logs'))->contains(fn ($i) => $i['name'] === self::NAME);
    }
};
