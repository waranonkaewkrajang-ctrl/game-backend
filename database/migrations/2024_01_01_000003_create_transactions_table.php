<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference_id', 64)->unique();

            $table->enum('type', [
                'deposit',
                'withdraw',
                'bet',
                'win',
                'bonus',
                'adjustment',
                'refund',
                'turnover_release',
            ]);

            $table->enum('direction', ['in', 'out']);

            $table->decimal('amount', 16, 2);
            $table->decimal('balance_before', 16, 2);
            $table->decimal('balance_after', 16, 2);

            $table->string('description')->nullable();
            $table->json('meta')->nullable();

            $table->enum('status', ['pending', 'completed', 'failed', 'cancelled'])->default('completed');

            $table->foreignId('processed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'created_at']);
            $table->index(['reference_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};