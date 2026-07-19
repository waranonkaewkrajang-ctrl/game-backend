<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('balance', 16, 2)->default(0.00);
            $table->decimal('bonus_balance', 16, 2)->default(0.00);
            $table->decimal('total_deposit', 16, 2)->default(0.00);
            $table->decimal('total_withdraw', 16, 2)->default(0.00);
            $table->decimal('total_bet', 16, 2)->default(0.00);
            $table->decimal('total_win', 16, 2)->default(0.00);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};