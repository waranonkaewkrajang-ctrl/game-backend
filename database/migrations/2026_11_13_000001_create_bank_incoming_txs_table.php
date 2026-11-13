<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * สมุดบัญชีเงินเข้า — ทุกข้อความ "เงินเข้า" ที่ bot อ่านได้ ลงทะเบียนที่นี่ "ครั้งเดียว"
 * fingerprint unique = ด่านกันซ้ำหลัก (restart / LINE หลุดแล้วโหลดแชทเก่า / cache ล้าง ก็ไม่เบิ้ล)
 * deposit_id / unmatched_deposit_id unique = 1 เงินเข้า ผูกได้แค่ 1 รายการฝาก / 1 ยอดค้าง
 * ตารางใหม่ล้วน — ไม่แก้ตารางเดิม
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_incoming_txs', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('bank', 50)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('tx_time_raw', 100)->nullable();   // ข้อความเวลาตามที่ธนาคารส่งมา
            $table->dateTime('tx_at')->nullable();             // เวลาที่แปลงแล้ว (เวลาไทย) — null = แปลงไม่ได้
            $table->string('from_account')->nullable();
            $table->string('from_account_4', 4)->nullable();
            $table->string('source', 20)->nullable();          // scb_json / text
            $table->string('mode', 10)->default('live');       // live / backlog (bot เพิ่งเปิด)
            // received → auto_approved | unmatched | review | manual_approved
            $table->string('status', 20)->default('received')->index();
            $table->string('reason', 50)->nullable();          // รหัสเหตุผลที่ไม่ auto
            $table->text('note')->nullable();
            $table->unsignedBigInteger('deposit_id')->nullable()->unique();
            $table->unsignedBigInteger('unmatched_deposit_id')->nullable()->unique();
            $table->unsignedBigInteger('reported_by')->nullable();  // admin id ของ bot
            $table->unsignedInteger('seen_count')->default(1);      // ถูกส่งซ้ำมากี่ครั้ง
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_incoming_txs');
    }
};
