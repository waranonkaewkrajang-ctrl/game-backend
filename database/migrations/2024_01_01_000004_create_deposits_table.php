<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference_id', 64)->unique();

            $table->decimal('amount', 16, 2);
            $table->string('channel', 30);
            $table->string('from_bank', 10)->nullable();
            $table->string('from_account', 20)->nullable();
            $table->string('to_bank', 10)->nullable();
            $table->string('to_account', 20)->nullable();

            $table->string('slip_url')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected', 'auto_approved'])->default('pending');
            $table->string('reject_reason')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->unsignedBigInteger('promotion_id')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};