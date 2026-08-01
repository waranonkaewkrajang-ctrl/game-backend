<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('truewallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->nullable()->unique();
            $table->string('event_type')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('sender_mobile')->nullable();
            $table->string('receiver_mobile')->nullable();
            $table->string('message')->nullable();
            $table->enum('status', ['matched', 'unmatched', 'duplicate', 'ignored'])
                  ->default('unmatched');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deposit_id')->nullable()->constrained('deposits')->nullOnDelete();
            $table->text('raw_data')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('sender_mobile');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('truewallet_transactions');
    }
};
