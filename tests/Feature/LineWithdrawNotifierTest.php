<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Withdrawal;
use App\Services\LineWithdrawNotifier;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🆕 LINE withdrawal card notifier.
 * No database (the project's migrations cannot run on sqlite) — models are built in memory.
 */
class LineWithdrawNotifierTest extends TestCase
{
    private const URL = 'http://admin-api.test/withdraw-notify/events';
    private const SECRET = 'test-secret-0123456789abcdef0123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.withdraw_notify.url' => self::URL, 'services.withdraw_notify.secret' => self::SECRET]);
    }

    private function withdrawal(?string $phone = '0910120334'): Withdrawal
    {
        $user = new User();
        $user->forceFill(['id' => 7, 'username' => 'member07', 'phone' => $phone, 'password' => 'x']);
        $w = new Withdrawal();
        $w->forceFill([
            'id' => 55, 'user_id' => 7, 'reference_id' => 'WD-20261112-ABCDEFGHIJ', 'amount' => 1500,
            'to_bank' => 'KBANK', 'to_account' => '1234567890', 'to_name' => 'Somchai', 'status' => 'pending',
            'balance_before' => 9000, 'balance_after' => 7500,
        ]);
        $w->created_at = Carbon::parse('2026-11-12T07:00:00+07:00');
        $w->setRelation('user', $user);
        return $w;
    }

    public function test_payload_has_only_what_the_card_needs(): void
    {
        $p = (new LineWithdrawNotifier())->payload($this->withdrawal(), LineWithdrawNotifier::PENDING);
        $this->assertSame('WD-20261112-ABCDEFGHIJ', $p['withdraw_id']);
        $this->assertSame('0910120334', $p['phone']);
        $this->assertSame('pending', $p['status']);
        $this->assertSame(1500.0, $p['amount']);
        $this->assertSame('KBANK', $p['bank_code']);
        $this->assertArrayNotHasKey('balance_before', $p);   // no balances
        $this->assertArrayNotHasKey('to_name', $p);          // no customer name
        $this->assertNull((new LineWithdrawNotifier())->payload($this->withdrawal(), 'deleted'));
        $this->assertNull((new LineWithdrawNotifier())->payload($this->withdrawal(null), 'pending')); // no phone → skip
    }

    public function test_sends_after_the_response_with_the_secret_header(): void
    {
        Http::fake([self::URL => Http::response(['status' => 'queued'], 202)]);
        (new LineWithdrawNotifier())->notify($this->withdrawal(), LineWithdrawNotifier::APPROVED);
        Http::assertNothingSent();          // not during the request…
        $this->app->terminate();            // …only after the response is sent
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::URL
            && $r->header('x-withdraw-notify-key')[0] === self::SECRET
            && $r['status'] === 'approved' && $r['withdraw_id'] === 'WD-20261112-ABCDEFGHIJ');
    }

    public function test_never_throws_when_admin_api_is_down_or_errors(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));
        $this->assertFalse((new LineWithdrawNotifier())->send(['withdraw_id' => 'x', 'status' => 'pending']));
        Http::fake([self::URL => Http::response('boom', 500)]);
        $this->assertFalse((new LineWithdrawNotifier())->send(['withdraw_id' => 'x', 'status' => 'pending']));
        (new LineWithdrawNotifier())->notify($this->withdrawal(), LineWithdrawNotifier::REFUNDED);
        $this->app->terminate(); // must not throw
        $this->assertTrue(true);
    }

    public function test_disabled_without_url_or_with_a_weak_secret(): void
    {
        Http::fake();
        config(['services.withdraw_notify.url' => null]);
        (new LineWithdrawNotifier())->notify($this->withdrawal(), LineWithdrawNotifier::PENDING);
        config(['services.withdraw_notify.url' => self::URL, 'services.withdraw_notify.secret' => 'short']);
        (new LineWithdrawNotifier())->notify($this->withdrawal(), LineWithdrawNotifier::PENDING);
        (new LineWithdrawNotifier())->notify(null, LineWithdrawNotifier::PENDING);
        $this->app->terminate();
        Http::assertNothingSent();
    }
}
