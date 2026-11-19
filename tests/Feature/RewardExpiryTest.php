<?php

namespace Tests\Feature;

use App\Console\Commands\CalculateCashback;
use App\Models\Reward;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Bonus\CreatesMoneySchema;
use Tests\TestCase;

/**
 * rewards:expire + การปัดเศษยอดคืน. sqlite in-memory only — no real database, no real money.
 */
class RewardExpiryTest extends TestCase
{
    use CreatesMoneySchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMoneySchema();
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
        $this->user = User::create([
            'username' => 'rw_user', 'phone' => '0800000011', 'password' => 'x',
            'bank_code' => 'SCB', 'bank_account' => '1234567891', 'bank_name' => 'Test User',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reward(string $createdAt, string $status = 'pending'): Reward
    {
        $r = Reward::create(['user_id' => $this->user->id, 'type' => 'cashback', 'amount' => 10, 'status' => $status]);
        $r->forceFill(['created_at' => $createdAt])->save();
        return $r;
    }

    public function test_expires_exactly_what_the_customer_can_no_longer_claim(): void
    {
        Setting::setValue('reward_expire_days', 1);
        Carbon::setTestNow('2026-11-19 10:00:00');
        $past    = $this->reward('2026-11-18 09:00:12');           // เกิน 24 ชม. → ลูกค้ารับไม่ได้แล้ว
        $live    = $this->reward('2026-11-19 09:00:12');           // ยังรับได้
        $edge    = $this->reward('2026-11-18 10:00:00');           // ครบพอดี = ยังรับได้ (>= cutoff)
        $claimed = $this->reward('2026-11-10 09:00:00', 'claimed'); // รับแล้วต้องไม่ถูกแตะ

        $this->artisan('rewards:expire')->assertSuccessful();

        $this->assertSame('expired', $past->fresh()->status);
        $this->assertSame('pending', $live->fresh()->status);
        $this->assertSame('pending', $edge->fresh()->status);
        $this->assertSame('claimed', $claimed->fresh()->status);
    }

    public function test_no_expiry_setting_changes_nothing(): void
    {
        Carbon::setTestNow('2026-11-19 10:00:00');
        $old = $this->reward('2026-10-01 09:00:00');

        $this->artisan('rewards:expire')->assertSuccessful();

        $this->assertSame('pending', $old->fresh()->status);
    }

    public function test_percent_rounds_half_up_in_satang(): void
    {
        $this->assertSame(7.74, CalculateCashback::percentOf(154.70, 5));   // เดิมได้ 7.73
        $this->assertSame(57.70, CalculateCashback::percentOf(1153.95, 5));
        $this->assertSame(30.79, CalculateCashback::percentOf(615.76, 5));
        $this->assertSame(2.32, CalculateCashback::percentOf(257.95, 0.9));
        $this->assertSame(0.0, CalculateCashback::percentOf(0, 5));
    }
}
