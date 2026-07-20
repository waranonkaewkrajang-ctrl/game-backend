<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            // 🟢 ฟิลด์ที่เพิ่มเข้ามาสำหรับจัดการแบนเนอร์
            $table->string('image_url'); // เก็บลิงก์รูปภาพ
            $table->boolean('is_active')->default(true); // สถานะเปิด/ปิด การแสดงผล
            $table->integer('sort_order')->default(0); // ลำดับการแสดงโชว์ (เรียงจากน้อยไปมาก)
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};