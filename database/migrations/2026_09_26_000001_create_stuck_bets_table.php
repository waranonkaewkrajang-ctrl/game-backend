<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // รายการเดิมพันที่หักเงินแล้วแต่ไม่มีผลคืนกลับมา
        Schema::create('stuck_bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('round_id', 191);
            $table->string('txn_id', 100)->nullable();       // เลขอ้างอิงจากค่าย เช่น BAC-110221150626
            $table->decimal('bet_amount', 15, 2);
            $table->decimal('amb_payout', 15, 2)->nullable(); // ค่ายบอกว่าควรคืนเท่าไหร่
            $table->string('amb_status', 30)->nullable();     // SETTLED/WIN, NOT_FOUND ฯลฯ
            $table->string('status', 20)->default('pending'); // pending | refunded | ignored
            $table->decimal('refund_amount', 15, 2)->default(0);
            $table->unsignedBigInteger('refunded_by')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('bet_at');                      // เวลาที่หักเงิน
            $table->timestamps();

            $table->unique(['provider', 'round_id'], 'uniq_stuck_round');
            $table->index(['status', 'bet_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stuck_bets');
    }
};