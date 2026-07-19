<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 30);
            $table->string('game_id', 50);
            $table->string('game_name')->nullable();
            $table->string('round_id', 100)->unique();

            $table->enum('action', ['bet', 'win', 'refund', 'bonus']);
            $table->decimal('bet_amount', 16, 2)->default(0);
            $table->decimal('win_amount', 16, 2)->default(0);
            $table->decimal('balance_before', 16, 2);
            $table->decimal('balance_after', 16, 2);

            $table->json('raw_data')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['provider', 'game_id']);
            $table->index(['round_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_logs');
    }
};