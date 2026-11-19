<?php

namespace Tests\Feature;

use App\Http\Controllers\Internal\InternalAdminController;
use App\Models\Reward;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Bonus\CreatesMoneySchema;
use Tests\TestCase;

/**
 * ระบบแนะนำเพื่อน: วันหมดอายุแยก, กันสร้างซ้ำ, รหัสแนะนำผิด, ปิดเบอร์เพื่อน, สัญญาณชวนตัวเอง
 * sqlite in-memory only — no real database, no real money.
 */
class ReferralSystemTest extends TestCase
{
    use CreatesMoneySchema;

    private User $referrer;
    private User $friend;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMoneySchema();
        Http::fake();
        Schema::create('rewards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id');
            $t->string('type');
            $t->decimal('amount', 15, 2);
            $t->string('status')->default('pending');
            $t->string('description')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('claimed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('game_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id');
            $t->string('action');
            $t->decimal('bet_amount', 16, 2)->default(0);
            $t->decimal('win_amount', 16, 2)->default(0);
            $t->timestamps();
        });
        $this->referrer = User::create([
            'username' => '0811111111', 'phone' => '0811111111', 'password' => 'x', 'referral_code' => 'ABCD1234',
            'bank_code' => 'SCB', 'bank_account' => '1111111111', 'bank_name' => 'นาย สมชาย ใจดี', 'last_login_ip' => '1.2.3.4',
        ]);
        $this->friend = User::create([
            'username' => '0822223333', 'phone' => '0822223333', 'password' => 'x', 'referral_code' => 'EFGH5678',
            'bank_code' => 'KBANK', 'bank_account' => '2222222222', 'bank_name' => 'สมหญิง รักดี', 'last_login_ip' => '5.6.7.8',
            'referred_by' => $this->referrer->id,
        ]);
        Wallet::create(['user_id' => $this->referrer->id, 'balance' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reward(string $type, string $createdAt, float $amount = 10, array $meta = []): Reward
    {
        $r = Reward::create(['user_id' => $this->referrer->id, 'type' => $type, 'amount' => $amount, 'status' => 'pending', 'meta' => $meta]);
        $r->forceFill(['created_at' => $createdAt])->save();
        return $r;
    }

    private function friendLoses(string $date, float $bet, float $win): void
    {
        DB::table('game_logs')->insert([
            ['user_id' => $this->friend->id, 'action' => 'bet', 'bet_amount' => $bet, 'win_amount' => 0, 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"],
            ['user_id' => $this->friend->id, 'action' => 'win', 'bet_amount' => 0, 'win_amount' => $win, 'created_at' => "$date 12:00:00", 'updated_at' => "$date 12:00:00"],
        ]);
    }

    public function test_referral_expiry_falls_back_to_reward_expire_days(): void
    {
        Setting::setValue('reward_expire_days', 1);
        $this->assertSame(1, Reward::expireDaysFor('referral'));
        $this->assertSame(1, Reward::expireDaysFor('cashback'));

        Setting::setValue('referral_expire_days', '');   // เว้นว่าง = เหมือนเดิม
        $this->assertSame(1, Reward::expireDaysFor('referral'));

        Setting::setValue('referral_expire_days', 7);
        $this->assertSame(7, Reward::expireDaysFor('referral'));
        $this->assertSame(1, Reward::expireDaysFor('cashback'));  // ยอดเสียไม่เปลี่ยน
    }

    public function test_expire_uses_each_types_own_days(): void
    {
        Setting::setValue('reward_expire_days', 1);
        Setting::setValue('referral_expire_days', 7);
        Carbon::setTestNow('2026-11-19 10:00:00');

        $cashbackOld = $this->reward('cashback', '2026-11-17 09:00:00');
        $referralMid = $this->reward('referral', '2026-11-17 09:00:00');
        $referralOld = $this->reward('referral', '2026-11-11 09:00:00');

        $this->artisan('rewards:expire')->assertSuccessful();

        $this->assertSame('expired', $cashbackOld->fresh()->status);
        $this->assertSame('pending', $referralMid->fresh()->status);
        $this->assertSame('expired', $referralOld->fresh()->status);
    }

    public function test_claim_referral_uses_referral_days(): void
    {
        Setting::setValue('reward_expire_days', 1);
        Setting::setValue('referral_expire_days', 7);
        Carbon::setTestNow('2026-11-19 10:00:00');
        $this->reward('referral', '2026-11-16 09:00:00', 4.50);   // 3 วันก่อน: ยอดเสียหมดแล้ว แต่ค่าแนะนำยังรับได้

        $this->actingAs($this->referrer, 'sanctum')
            ->getJson('/api/rewards/summary')
            ->assertJsonPath('data.referral.pending', 4.5)
            ->assertJsonPath('data.referral_expire_days', 7)
            ->assertJsonPath('data.expire_days', 1);

        $this->postJson('/api/rewards/claim/referral')->assertOk()->assertJsonPath('data.amount', 4.5);
    }

    public function test_rerun_does_not_duplicate_referral_when_nobody_got_cashback(): void
    {
        // วันที่ไม่มี cashback เลย เดิมรันซ้ำแล้วค่าแนะนำซ้ำ
        Setting::setValue('cashback_percent', 0);
        Setting::setValue('referral_percent', 1);
        $this->friendLoses('2026-11-18', 600, 100);

        $this->artisan('cashback:calculate', ['--date' => '2026-11-18'])->assertSuccessful();
        $this->artisan('cashback:calculate', ['--date' => '2026-11-18'])->assertSuccessful();

        $rows = Reward::where('type', 'referral')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('5.00', $rows[0]->amount);
        $this->assertSame($this->referrer->id, $rows[0]->user_id);
    }

    public function test_rerun_with_cashback_creates_each_row_once(): void
    {
        Setting::setValue('cashback_percent', 5);
        Setting::setValue('referral_percent', 1);
        $this->friendLoses('2026-11-18', 600, 100);

        $this->artisan('cashback:calculate', ['--date' => '2026-11-18'])->assertSuccessful();
        $this->artisan('cashback:calculate', ['--date' => '2026-11-18'])->assertSuccessful();

        $this->assertSame(1, Reward::where('type', 'cashback')->count());
        $this->assertSame(1, Reward::where('type', 'referral')->count());
        $this->assertSame('25.00', Reward::where('type', 'cashback')->value('amount'));
    }

    public function test_history_and_friends_hide_the_friends_phone(): void
    {
        Setting::setValue('referral_percent', 0.9);
        Carbon::setTestNow('2026-11-19 10:00:00');
        $this->reward('referral', '2026-11-19 09:00:00', 3.20, ['date' => '2026-11-18', 'from_user_id' => $this->friend->id, 'from_username' => '0822223333']);

        $this->actingAs($this->referrer, 'sanctum');
        $hist = $this->getJson('/api/rewards/history?type=referral')->assertOk();
        $this->assertSame('082-xxx-3333', $hist->json('data.data.0.meta.from_username'));
        $this->assertStringNotContainsString('0822223333', $hist->getContent());

        $res = $this->getJson('/api/rewards/referral/friends')->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.friends.0.name', '082-xxx-3333')
            ->assertJsonPath('data.friends.0.earned', 3.2)
            ->assertJsonPath('data.terms.percent', 0.9);
        $this->assertStringNotContainsString('0822223333', $res->getContent());
        $this->assertStringNotContainsString('2222222222', $res->getContent());
    }

    public function test_unknown_referral_code_is_rejected_instead_of_ignored(): void
    {
        $this->postJson('/api/auth/register', [
            'username' => '0833334444', 'phone' => '0833334444', 'password' => 'secret12', 'password_confirmation' => 'secret12',
            'bank_code' => 'SCB', 'bank_account' => '3333333333', 'bank_name' => 'ทดสอบ ระบบ', 'referral_code' => 'NOPE9999',
        ])->assertStatus(422)->assertJsonValidationErrors('referral_code');

        $this->assertNull(User::where('phone', '0833334444')->first());
    }

    public function test_self_referral_flags(): void
    {
        $twin = new User(['bank_name' => 'สมชาย  ใจดี', 'last_login_ip' => '1.2.3.4']);
        $this->assertSame(['same_bank_name', 'same_ip'], InternalAdminController::referralFlags($this->referrer, $twin));
        $this->assertSame([], InternalAdminController::referralFlags($this->referrer, $this->friend));
    }
}
