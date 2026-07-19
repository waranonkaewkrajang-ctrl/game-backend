<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference_id', 64)->unique();

            $table->decimal('amount', 16, 2);
            $table->string('to_bank', 10);
            $table->string('to_account', 20);
            $table->string('to_name', 100);

            $table->enum('status', ['pending', 'processing', 'approved', 'rejected', 'auto_approved'])->default('pending');
            $table->string('reject_reason')->nullable();

            $table->decimal('balance_before', 16, 2);
            $table->decimal('balance_after', 16, 2);

            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};