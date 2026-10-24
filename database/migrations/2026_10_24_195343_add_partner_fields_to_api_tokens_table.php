<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            // เช็คก่อนเพิ่มทุกคอลัมน์ กันชนกับของเดิม
            if (!Schema::hasColumn('api_tokens', 'is_partner')) {
                $table->boolean('is_partner')->default(false)->index();
            }
            if (!Schema::hasColumn('api_tokens', 'partner_name')) {
                $table->string('partner_name', 150)->nullable();
            }
            if (!Schema::hasColumn('api_tokens', 'webhook_url')) {
                $table->string('webhook_url', 500)->nullable();
            }
            if (!Schema::hasColumn('api_tokens', 'webhook_secret')) {
                $table->string('webhook_secret', 191)->nullable();
            }
            if (!Schema::hasColumn('api_tokens', 'intent_ttl_minutes')) {
                $table->unsignedSmallInteger('intent_ttl_minutes')->default(30);
            }
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            foreach ([
                'is_partner',
                'partner_name',
                'webhook_url',
                'webhook_secret',
                'intent_ttl_minutes',
            ] as $col) {
                if (Schema::hasColumn('api_tokens', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};