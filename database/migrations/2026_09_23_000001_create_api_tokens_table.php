<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Token สำหรับให้ระบบภายนอก (LINE Chat) เรียก API
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);                        // ชื่อกำกับ เช่น "ระบบ LINE Chat"
            $table->string('token_hash', 64)->unique();         // เก็บแบบเข้ารหัสทางเดียว
            $table->string('token_prefix', 12);                 // 8 ตัวแรก ไว้แสดงในหน้าจอ
            $table->text('allowed_ips')->nullable();            // IP ที่อนุญาต คั่นด้วย , (ว่าง = ทุก IP)
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->unsignedInteger('calls_count')->default(0);
            $table->timestamps();
        });

        // ประวัติการเรียก API (ไว้ตรวจย้อนหลัง)
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            $table->string('endpoint', 100);
            $table->string('ip', 45)->nullable();
            $table->string('query_value', 50)->nullable();      // เบอร์ที่ค้นหา
            $table->unsignedSmallInteger('status_code');
            $table->string('note', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('api_tokens');
    }
};