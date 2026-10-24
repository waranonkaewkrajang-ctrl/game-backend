<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_deposit_intents', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('api_token_id')->index();        // พาร์ทเนอร์เจ้าของคำขอ
            $table->unsignedBigInteger('partner_account_id')->index();  // บัญชีที่ให้โอนเข้า

            // รหัสสมาชิกฝั่งพาร์ทเนอร์ (เราไม่รู้ว่าเขาเก็บยังไง เก็บเป็น string)
            $table->string('member_ref', 191)->index();

            $table->decimal('amount_requested', 15, 2);   // ราคาจริง เช่น 300.00 — ใช้ตัดสินสิทธิ์
            $table->decimal('amount_unique', 15, 2);      // ยอดที่ต้องโอนจริง เช่น 300.17

            $table->enum('status', ['waiting', 'paid', 'expired', 'cancelled'])
                  ->default('waiting');

            // ผูกกับรายการเงินเข้าจริงเมื่อจับคู่ได้
            $table->unsignedBigInteger('bank_statement_id')->nullable()->index();
            $table->decimal('amount_paid', 15, 2)->nullable();
            $table->string('reference', 100)->nullable();   // เลขอ้างอิงที่ส่งให้พาร์ทเนอร์
            $table->string('from_account_4', 10)->nullable();
            $table->string('from_name', 150)->nullable();

            $table->timestamp('expires_at')->index();
            $table->timestamp('paid_at')->nullable();

            // สถานะการแจ้งกลับ (webhook)
            $table->timestamp('notified_at')->nullable();
            $table->unsignedSmallInteger('notify_attempts')->default(0);
            $table->text('notify_last_error')->nullable();

            $table->timestamps();

            // ใช้ตอนเงินเข้า: หา intent ที่ยอดตรง + ยังรออยู่ + ยังไม่หมดอายุ
            $table->index(['amount_unique', 'status', 'expires_at'], 'pdi_match_index');

            // กันจองยอดซ้ำในบัญชีเดียวกันขณะที่ยังรออยู่
            $table->index(['partner_account_id', 'amount_unique', 'status'], 'pdi_reserve_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_deposit_intents');
    }
};