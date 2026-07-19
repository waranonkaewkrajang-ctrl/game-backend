<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 50)->unique();
            $table->string('phone', 20)->unique();
            $table->string('password');
            $table->string('full_name', 100)->nullable();
            $table->string('line_id', 100)->nullable();

            // ข้อมูลธนาคาร
            $table->string('bank_code', 10)->nullable();
            $table->string('bank_account', 20)->nullable();
            $table->string('bank_name', 100)->nullable();

            // สถานะ
            $table->enum('status', ['active', 'suspended', 'banned'])->default('active');
            $table->boolean('is_verified')->default(false);

            // Referral
            $table->string('referral_code', 20)->unique()->nullable();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();

            $table->ipAddress('last_login_ip')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status']);
            $table->index(['phone']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};