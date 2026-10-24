<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_accounts', function (Blueprint $table) {
            $table->id();

            // ผูกกับ token ของพาร์ทเนอร์ (ตาราง api_tokens)
            $table->unsignedBigInteger('api_token_id')->index();

            // ข้อมูลบัญชีรับเงิน
            $table->string('bank_code', 20);                 // SCB, KBANK ...
            $table->string('bank_account', 30);              // เลขบัญชี
            $table->string('account_name', 150);             // ชื่อบัญชี
            $table->string('promptpay_id', 50)->nullable();  // เบอร์/เลขบัตร สำหรับสร้าง QR

            // บอกว่าบอทตัวไหนเป็นคนอ่านบัญชีนี้ (เช่น main, partner1)
            $table->string('monitor_tag', 50)->default('main')->index();

            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['bank_code', 'bank_account'], 'partner_accounts_bank_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_accounts');
    }
};