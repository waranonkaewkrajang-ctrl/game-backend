<?php

namespace Tests\Feature\Bonus;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * สร้างตารางเงินขั้นต่ำบน sqlite in-memory (migration ของโปรเจกต์รันบน sqlite ไม่ได้)
 * ห้ามใช้กับฐานอื่น — assertion ใน createMoneySchema() กันไว้
 */
trait CreatesMoneySchema
{
    protected function createMoneySchema(): void
    {
        $this->assertSame('sqlite', config('database.default'), 'เทสต์นี้ต้องรันบน sqlite in-memory เท่านั้น');
        $this->assertSame(':memory:', config('database.connections.sqlite.database'), 'เทสต์นี้ต้องรันบน sqlite in-memory เท่านั้น');

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('username')->unique();
            $t->string('phone')->unique();
            $t->string('password');
            foreach (['full_name', 'line_id', 'bank_code', 'bank_account', 'bank_name', 'referral_code', 'last_login_ip', 'amb_username', 'two_factor_secret'] as $c) {
                $t->string($c)->nullable();
            }
            $t->string('status')->default('active');
            $t->boolean('is_verified')->default(false);
            $t->boolean('two_factor_enabled')->default(false);
            $t->unsignedBigInteger('referred_by')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('wallets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->unique();
            foreach (['balance', 'bonus_balance', 'total_deposit', 'total_withdraw', 'total_bet', 'total_win', 'point_balance'] as $c) {
                $t->decimal($c, 16, 2)->default(0);
            }
            $t->integer('ticket_balance')->default(0);
            $t->timestamps();
        });

        Schema::create('transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('reference_id')->unique();
            $t->string('type');
            $t->string('direction');
            $t->decimal('amount', 16, 2);
            $t->decimal('balance_before', 16, 2);
            $t->decimal('balance_after', 16, 2);
            $t->string('description')->nullable();
            $t->json('meta')->nullable();
            $t->string('status')->default('completed');
            $t->unsignedBigInteger('processed_by')->nullable();
            $t->timestamps();
        });

        Schema::create('promotions', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('image_url')->nullable();
            $t->string('type');
            $t->decimal('min_deposit', 16, 2)->default(0);
            $t->decimal('max_bonus', 16, 2)->default(0);
            $t->decimal('bonus_percent', 5, 2)->default(0);
            $t->decimal('turnover_multiplier', 5, 2)->default(1);
            $t->decimal('max_withdraw', 16, 2)->nullable();
            $t->json('allowed_categories')->nullable();
            $t->json('allowed_providers')->nullable();
            $t->json('allowed_games')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('max_claims')->nullable();
            $t->integer('claims_per_user')->default(1);
            $t->timestamp('start_at')->nullable();
            $t->timestamp('end_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('deposits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('reference_id')->unique();
            $t->decimal('amount', 16, 2);
            $t->string('channel');
            foreach (['from_bank', 'from_account', 'to_bank', 'to_account', 'slip_url', 'reject_reason', 'approved_method'] as $c) {
                $t->string($c)->nullable();
            }
            $t->string('status')->default('pending');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('promotion_id')->nullable();
            $t->timestamps();
        });

        Schema::create('withdrawals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('reference_id')->unique();
            $t->decimal('amount', 16, 2);
            $t->string('to_bank');
            $t->string('to_account');
            $t->string('to_name');
            $t->string('status')->default('pending');
            $t->string('reject_reason')->nullable();
            $t->decimal('balance_before', 16, 2);
            $t->decimal('balance_after', 16, 2);
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('processing_by')->nullable();
            $t->timestamp('processing_at')->nullable();
            $t->timestamps();
        });

        Schema::create('unmatched_deposits', function (Blueprint $t) {
            $t->id();
            $t->string('bank')->nullable();
            $t->decimal('amount', 12, 2);
            $t->string('from_account')->nullable();
            $t->string('tx_time')->nullable();
            $t->string('status')->default('pending');
            $t->string('note')->nullable();
            $t->unsignedBigInteger('matched_user_id')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });

        Schema::create('promotion_claims', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('promotion_id')->nullable();
            $t->unsignedBigInteger('deposit_id')->nullable();
            $t->string('type', 30)->default('free_credit');
            $t->decimal('bonus_amount', 16, 2);
            $t->decimal('turnover_multiplier', 8, 2)->default(1);
            $t->decimal('turnover_required', 16, 2)->default(0);
            $t->decimal('turnover_current', 16, 2)->default(0);
            $t->boolean('turnover_completed')->default(false);
            $t->string('status', 20)->default('active');
            $t->unsignedBigInteger('granted_by')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('expired_at')->nullable();
            $t->string('note')->nullable();
            $t->json('allowed_categories')->nullable();
            $t->json('allowed_providers')->nullable();
            $t->json('allowed_games')->nullable();
            $t->timestamps();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }
}
