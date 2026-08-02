<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ช่องรางวัล
        Schema::create('spin_wheel_prizes', function (Blueprint $table) {
            $table->id();
            $table->string('label');                    // ชื่อรางวัล เช่น "50 บาท"
            $table->string('type');                     // credit, bonus, free_spin, nothing
            $table->decimal('value', 15, 2)->default(0); // มูลค่า
            $table->string('color')->default('#7c3aed'); // สีช่อง
            $table->string('icon')->nullable();          // emoji หรือ icon
            $table->decimal('probability', 5, 2)->default(10); // % โอกาส
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ประวัติการหมุน
        Schema::create('spin_wheel_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('prize_id')->constrained('spin_wheel_prizes');
            $table->string('prize_label');
            $table->string('prize_type');
            $table->decimal('prize_value', 15, 2)->default(0);
            $table->boolean('is_claimed')->default(false);
            $table->timestamps();

            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spin_wheel_history');
        Schema::dropIfExists('spin_wheel_prizes');
    }
};