<?php

namespace Tests\Feature\Perf;

use Illuminate\Support\Facades\Schema;
use Tests\Feature\Bonus\CreatesMoneySchema;
use Tests\TestCase;

class TransactionsIndexMigrationTest extends TestCase
{
    use CreatesMoneySchema;

    public function test_index_migration_is_additive_idempotent_and_reversible(): void
    {
        $this->createMoneySchema();
        $m = require base_path('database/migrations/2026_11_17_000002_add_type_created_amount_index_to_transactions.php');
        $names = fn () => collect(Schema::getIndexes('transactions'))->pluck('name')->all();
        $before = $names();

        $m->up();
        $m->up(); // second run is a no-op
        $this->assertContains('tx_type_created_amount', $names());
        $this->assertContains('tx_created_at', $names());
        $this->assertSame(count($before) + 2, count($names()));

        $m->down();
        $this->assertEqualsCanonicalizing($before, $names());
    }
}
