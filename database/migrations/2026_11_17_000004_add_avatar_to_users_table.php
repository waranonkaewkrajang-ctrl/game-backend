<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.avatar = the avatar id ("01".."NN") a member picked on the website; NULL = website default.
 * Additive only. MySQL 8: nullable column at the end → ALGORITHM=INSTANT (metadata change, no table copy,
 * no row lock); lock_wait_timeout 5 s so it gives up instead of queueing behind long transactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'avatar')) {
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET SESSION lock_wait_timeout = 5');
            DB::statement('ALTER TABLE users ADD COLUMN avatar VARCHAR(8) NULL, ALGORITHM=INSTANT');
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar', 8)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'avatar')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('avatar');
            });
        }
    }
};
