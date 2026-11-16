<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use App\Support\WithdrawMaintenance;
use App\Support\WithdrawMaintenanceException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Bonus\CreatesMoneySchema;
use Tests\TestCase;

/**
 * ปิดปรับปรุงถอนเงิน (ตั้งค่าการเงิน). sqlite in-memory only — no real database, no real money.
 */
class WithdrawMaintenanceTest extends TestCase
{
    use CreatesMoneySchema;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMoneySchema();
        Http::fake();
        config(['logging.default' => 'null']);
        $this->user = User::create([
            'username' => 'wd_user', 'phone' => '0800000009', 'password' => 'x',
            'bank_code' => 'SCB', 'bank_account' => '1234567890', 'bank_name' => 'Test User',
        ]);
        Wallet::create(['user_id' => $this->user->id, 'balance' => 5000]);
    }

    public function test_off_by_default_with_default_texts(): void
    {
        $s = WithdrawMaintenance::state();
        $this->assertFalse($s['enabled']);
        $this->assertSame(WithdrawMaintenance::DEFAULT_TITLE, $s['title']);
        $this->assertSame(WithdrawMaintenance::DEFAULT_MESSAGE, $s['message']);
    }

    public function test_on_blocks_new_withdrawals_and_keeps_the_balance(): void
    {
        Setting::create(['key' => 'withdraw_maintenance', 'value' => 'true']);
        Setting::create(['key' => 'withdraw_maintenance_title', 'value' => 'ธนาคารปิดปรับปรุง']);
        Setting::create(['key' => 'withdraw_maintenance_message', 'value' => 'ถอนได้อีกครั้ง 02:00 น.']);

        try {
            app(WithdrawalService::class)->createRequest($this->user->fresh(), 500);
            $this->fail('withdrawal should be refused');
        } catch (WithdrawMaintenanceException $e) {
            $this->assertSame('ธนาคารปิดปรับปรุง', $e->title);
            $this->assertSame('ถอนได้อีกครั้ง 02:00 น.', $e->getMessage());
        }
        $this->assertSame(0, Withdrawal::count());
        $this->assertEquals(5000, Wallet::where('user_id', $this->user->id)->value('balance'));
    }

    public function test_api_returns_the_popup_texts_and_switching_off_works_immediately(): void
    {
        Setting::create(['key' => 'withdraw_maintenance', 'value' => 'true']);
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/withdrawals', ['amount' => 500])
            ->assertStatus(400)
            ->assertJson(['code' => 'withdraw_maintenance', 'title' => WithdrawMaintenance::DEFAULT_TITLE]);
        $this->getJson('/api/finance/settings')->assertJsonPath('data.withdraw_maintenance.enabled', true);

        Setting::where('key', 'withdraw_maintenance')->update(['value' => 'false']);
        $this->getJson('/api/finance/settings')->assertJsonPath('data.withdraw_maintenance.enabled', false);
        $this->assertFalse(WithdrawMaintenance::state()['enabled']);
    }
}
