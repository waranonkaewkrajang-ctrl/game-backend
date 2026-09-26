<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ประเภทเกมที่เล่นได้ระหว่างติดโปร — null = เล่นได้ทุกประเภท (ค่าเดิม ไม่กระทบโปรเก่า)
        Schema::table('promotions', function (Blueprint $table) {
            $table->json('allowed_categories')->nullable()->after('turnover_multiplier');
        });

        // เก็บซ้ำตอนลูกค้ารับโปร — กันกรณีแอดมินแก้โปรทีหลังแล้วกระทบคนที่รับไปแล้ว
        Schema::table('promotion_claims', function (Blueprint $table) {
            $table->json('allowed_categories')->nullable()->after('turnover_multiplier');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', fn (Blueprint $t) => $t->dropColumn('allowed_categories'));
        Schema::table('promotion_claims', fn (Blueprint $t) => $t->dropColumn('allowed_categories'));
    }
};