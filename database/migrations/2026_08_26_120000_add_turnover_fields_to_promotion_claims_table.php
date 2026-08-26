<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) แก้ status จาก ENUM เดิมเป็น varchar (เพิ่ม 'expired' ได้)
        DB::statement("ALTER TABLE promotion_claims MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'");

        // 2) แก้ promotion_id เป็น nullable (เครดิตฟรีไม่มี promotion)
        Schema::table('promotion_claims', function (Blueprint $table) {
            $table->unsignedBigInteger('promotion_id')->nullable()->change();
        });

        // 3) เพิ่ม column ใหม่ 6 ตัว
        Schema::table('promotion_claims', function (Blueprint $table) {
            $table->string('type', 30)
                  ->default('free_credit')
                  ->after('deposit_id');

            $table->decimal('turnover_multiplier', 8, 2)
                  ->default(1)
                  ->after('bonus_amount');

            $table->unsignedBigInteger('granted_by')
                  ->nullable()
                  ->after('status');

            $table->timestamp('completed_at')
                  ->nullable()
                  ->after('granted_by');

            $table->timestamp('expired_at')
                  ->nullable()
                  ->after('completed_at');

            $table->string('note')
                  ->nullable()
                  ->after('expired_at');
        });

        // 4) เพิ่ม index
        Schema::table('promotion_claims', function (Blueprint $table) {
            $table->index(
                ['user_id', 'status', 'turnover_completed'],
                'idx_user_active_turnover'
            );
        });
    }

    public function down(): void
    {
        Schema::table('promotion_claims', function (Blueprint $table) {
            $table->dropIndex('idx_user_active_turnover');
            $table->dropColumn([
                'type', 'turnover_multiplier',
                'granted_by', 'completed_at', 'expired_at', 'note',
            ]);
        });

        DB::statement("ALTER TABLE promotion_claims MODIFY COLUMN status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active'");
    }
};