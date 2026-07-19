<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('product_id', 30);
            $table->string('game_code', 100);
            $table->string('game_name');
            $table->string('game_name_th')->nullable();
            $table->string('category', 50)->nullable();
            $table->string('type', 50)->nullable();
            $table->string('image_url')->nullable();
            $table->integer('rank')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'game_code']);
            $table->index(['product_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};