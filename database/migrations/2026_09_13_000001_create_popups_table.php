<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('popups', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->string('link_url')->nullable();
            $table->string('link_text')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('show_once')->default(false);      // แสดงครั้งเดียวต่อ user
            $table->integer('sort_order')->default(0);
            $table->timestamp('start_at')->nullable();          // เริ่มแสดงเมื่อไหร่
            $table->timestamp('end_at')->nullable();            // หยุดแสดงเมื่อไหร่
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('popups');
    }
};