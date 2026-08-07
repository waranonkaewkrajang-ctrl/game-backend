<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unmatched_deposits', function (Blueprint $table) {
            $table->id();
            $table->string('bank')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('from_account')->nullable();
            $table->string('tx_time')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->foreignId('matched_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unmatched_deposits');
    }
};