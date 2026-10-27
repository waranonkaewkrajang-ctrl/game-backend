<?php

namespace Tests\Feature\Bonus;

use App\Models\Deposit;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PromotionService;
use App\Services\WalletService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * งาน 4.1 — โบนัสโปรต้องได้ claim เดียว และผู้เรียก addBonus เดิมต้องได้ผลเหมือนเดิม
 *
 * ใช้ sqlite in-memory ตาม phpunit.xml และสร้างเฉพาะตารางที่ต้องใช้เอง
 * (migration ของโปรเจกต์รันบน sqlite ไม่ได้) — ไม่แตะฐานข้อมูลจริง
 */
class PromotionBonusTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'), 'เทสต์นี้ต้องรันบน sqlite in-memory เท่านั้น');
        $this->createSchema();

        $this->user = User::create([
            'username' => 'bonus_user', 'phone' => '0800000001', 'password' => 'x',
            'bank_code' => 'SCB', 'bank_account' => '1234567890', 'bank_name' => 'Test User',
        ]);
        Wallet::create(['user_id' => $this->user->id, 'balance' => 0]);
        Setting::create(['key' => 'default_turnover_multiplier', 'value' => '3']);
    }

    // ------------------------------------------------------------------
    //  applyBonus (โปรฝากเงิน)
    // ------------------------------------------------------------------

    public function test_promotion_bonus_creates_exactly_one_correct_claim(): void
    {
        $promo   = $this->promotion(['bonus_percent' => 10, 'turnover_multiplier' => 5, 'allowed_categories' => ['EGAMES']]);
        $deposit = $this->deposit(1000, $promo);

        $claim = app(PromotionService::class)->applyBonus($this->user, $deposit);

        $this->assertSame(1, PromotionClaim::count());
        $this->assertNotNull($claim);
        $this->assertSame('deposit_bonus', $claim->type);
        $this->assertSame($promo->id, $claim->promotion_id);
        $this->assertSame($deposit->id, $claim->deposit_id);
        $this->assertSame('100.00', $claim->bonus_amount);
        $this->assertSame('5.00', $claim->turnover_multiplier);
        $this->assertSame('5500.00', $claim->turnover_required);        // (1000 + 100) × 5
        $this->assertSame('active', $claim->status);
        $this->assertFalse($claim->turnover_completed);
        $this->assertSame(['EGAMES'], $claim->allowed_categories);

        $this->assertSame('100.00', $this->wallet()->balance);
        $this->assertSame('100.00', $this->wallet()->bonus_balance);
        $this->assertSame(1, Transaction::where('type', 'bonus')->count());
    }

    public function test_promotion_bonus_respects_max_bonus(): void
    {
        $promo = $this->promotion(['bonus_percent' => 50, 'max_bonus' => 200, 'turnover_multiplier' => 2]);

        $claim = app(PromotionService::class)->applyBonus($this->user, $this->deposit(1000, $promo));

        $this->assertSame('200.00', $claim->bonus_amount);
        $this->assertSame('2400.00', $claim->turnover_required);        // (1000 + 200) × 2
    }

    public function test_promotion_without_turnover_creates_completed_claim_and_counts_toward_limit(): void
    {
        $promo   = $this->promotion(['bonus_percent' => 10, 'turnover_multiplier' => 0, 'claims_per_user' => 1]);
        $service = app(PromotionService::class);

        $claim = $service->applyBonus($this->user, $this->deposit(500, $promo));

        $this->assertSame('completed', $claim->status);
        $this->assertTrue($claim->turnover_completed);
        $this->assertNotNull($claim->completed_at);
        $this->assertFalse(app(WalletService::class)->checkTurnover($this->user)['has_active'], 'ต้องไม่บล็อกการถอน');

        // รับครบสิทธิ์แล้ว ฝากรอบสองต้องไม่ได้โบนัสซ้ำ
        $this->assertNull($service->applyBonus($this->user, $this->deposit(500, $promo)));
        $this->assertSame(1, PromotionClaim::count());
        $this->assertSame('50.00', $this->wallet()->balance);
    }

    public function test_claim_limit_is_enforced_for_promotions_with_turnover(): void
    {
        $promo   = $this->promotion(['bonus_percent' => 10, 'turnover_multiplier' => 1, 'claims_per_user' => 1]);
        $service = app(PromotionService::class);

        $first = $service->applyBonus($this->user, $this->deposit(500, $promo));
        $first->update(['status' => 'completed', 'turnover_completed' => true]);   // ทำเทิร์นครบแล้ว

        $this->assertNull($service->applyBonus($this->user, $this->deposit(500, $promo)));
        $this->assertSame(1, PromotionClaim::count());
    }

    public function test_failure_while_creating_claim_rolls_back_wallet_and_transaction(): void
    {
        $promo = $this->promotion(['bonus_percent' => 10, 'turnover_multiplier' => 5]);
        PromotionClaim::creating(fn () => throw new \RuntimeException('จำลอง claim พัง'));

        try {
            app(PromotionService::class)->applyBonus($this->user, $this->deposit(1000, $promo));
            $this->fail('ต้องโยน exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('จำลอง claim พัง', $e->getMessage());
        }

        $this->assertSame('0.00', $this->wallet()->balance);
        $this->assertSame('0.00', $this->wallet()->bonus_balance);
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, PromotionClaim::count());
    }

    // ------------------------------------------------------------------
    //  ผู้เรียก addBonus เดิม — ต้องได้ผลเหมือนก่อนแก้
    // ------------------------------------------------------------------

    public function test_reward_claim_without_options_behaves_as_before(): void
    {
        // RewardController: addBonus($user, $amount, $desc, $meta) — ตัวคูณจาก setting (3)
        app(WalletService::class)->addBonus($this->user, 40, 'รับยอดเสีย', ['type' => 'claim_cashback']);

        $claim = PromotionClaim::sole();
        $this->assertSame('free_credit', $claim->type);
        $this->assertNull($claim->promotion_id);
        $this->assertSame('3.00', $claim->turnover_multiplier);
        $this->assertSame('120.00', $claim->turnover_required);
        $this->assertSame('active', $claim->status);
        $this->assertFalse($claim->turnover_completed);
        $this->assertNull($claim->completed_at);
        $this->assertSame('40.00', $this->wallet()->balance);
    }

    public function test_admin_free_credit_with_multiplier_behaves_as_before(): void
    {
        // AdminUserController::giveBonus
        app(WalletService::class)->addBonus($this->user, 100, 'เครดิตฟรี: x', [], 1, [
            'turnover_multiplier' => 2, 'type' => 'free_credit', 'expired_at' => null, 'note' => 'x',
        ]);

        $claim = PromotionClaim::sole();
        $this->assertSame('200.00', $claim->turnover_required);
        $this->assertSame('active', $claim->status);
        $this->assertSame(1, $claim->granted_by);
    }

    public function test_admin_free_credit_with_zero_multiplier_still_creates_no_claim(): void
    {
        app(WalletService::class)->addBonus($this->user, 100, 'เครดิตฟรี', [], 1, [
            'turnover_multiplier' => 0, 'type' => 'free_credit',
        ]);

        $this->assertSame(0, PromotionClaim::count());
        $this->assertSame('100.00', $this->wallet()->balance);
        $this->assertSame(1, Transaction::where('type', 'bonus')->count());
    }

    // ------------------------------------------------------------------

    private function wallet(): Wallet
    {
        return Wallet::where('user_id', $this->user->id)->first();
    }

    private function promotion(array $attrs): Promotion
    {
        return Promotion::create($attrs + [
            'title' => 'โปรทดสอบ', 'type' => 'deposit_bonus', 'min_deposit' => 100,
            'max_bonus' => 0, 'is_active' => true, 'claims_per_user' => 1,
        ]);
    }

    private function deposit(float $amount, Promotion $promo): Deposit
    {
        return Deposit::create([
            'user_id' => $this->user->id, 'reference_id' => 'DEP-' . uniqid(), 'amount' => $amount,
            'channel' => 'bank_transfer', 'status' => 'approved', 'promotion_id' => $promo->id,
        ]);
    }

    private function createSchema(): void
    {
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
