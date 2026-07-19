<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();

            $table->enum('type', [
                'welcome_bonus',
                'deposit_bonus',
                'cashback',
                'free_credit',
                'referral_bonus',
            ]);

            $table->decimal('min_deposit', 16, 2)->default(0);
            $table->decimal('max_bonus', 16, 2)->default(0);
            $table->decimal('bonus_percent', 5, 2)->default(0);
            $table->decimal('turnover_multiplier', 5, 2)->default(1);
            $table->decimal('max_withdraw', 16, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('max_claims')->nullable();
            $table->integer('claims_per_user')->default(1);

            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('promotion_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deposit_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('bonus_amount', 16, 2);
            $table->decimal('turnover_required', 16, 2)->default(0);
            $table->decimal('turnover_current', 16, 2)->default(0);
            $table->boolean('turnover_completed')->default(false);

            $table->enum('status', ['active', 'completed', 'cancelled'])->default('active');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_claims');
        Schema::dropIfExists('promotions');
    }
};