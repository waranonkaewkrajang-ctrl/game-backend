<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deposit_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->decimal('amount', 15, 2);
            $table->string('bank_code', 20)->nullable();
            $table->string('bank_account', 30)->nullable();
            $table->string('from_name', 150)->nullable();
            $table->string('reference_id', 100)->nullable();
            $table->enum('approved_method', ['auto', 'manual'])->default('manual');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('transaction_time')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};