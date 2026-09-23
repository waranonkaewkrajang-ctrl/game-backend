<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // กิจกรรมทั้งหมดที่แอดมินสร้างเอง
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);                  // link | football | lotto2 | popup
            $table->string('title', 100);
            $table->string('subtitle', 150)->nullable();

            // ภาพ
            $table->string('image_url', 255)->nullable();      // ภาพหลัก (WebP)
            $table->string('image_thumb', 255)->nullable();    // ภาพย่อ สำหรับกริดไอคอน
            $table->json('canvas_data')->nullable();           // ข้อมูลตัวออกแบบ (ไว้แก้ภาพซ้ำได้)

            // แสดงที่ไหน
            $table->json('slots');                             // ["home_banner","icon_grid",...]
            $table->json('pages')->nullable();                 // ["home","lobby"] — null = ทุกหน้า
            $table->string('link_url', 255)->nullable();       // กดแล้วไปไหน (ประเภท link)

            // การแสดงผล
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('badge', 20)->nullable();           // ป้าย เช่น ใหม่ / HOT
            $table->string('badge_color', 20)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('audience', 20)->default('all');    // all | member | guest
            $table->boolean('show_once')->default(false);      // สำหรับ popup

            $table->json('config')->nullable();                // ค่าเฉพาะของแต่ละประเภท
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('click_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        // คำทายของลูกค้า (ทายบอล / ทายหวย)
        Schema::create('activity_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('round_key', 50)->nullable();       // งวด/คู่ที่ทาย
            $table->string('answer', 50);                      // คำตอบที่ทาย
            $table->string('status', 20)->default('pending');  // pending | win | lose
            $table->decimal('reward_amount', 15, 2)->default(0);
            $table->string('reward_type', 20)->nullable();     // credit | ticket | point
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['activity_id', 'round_key', 'status']);
            $table->unique(['activity_id', 'user_id', 'round_key'], 'uniq_entry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_entries');
        Schema::dropIfExists('activities');
    }
};