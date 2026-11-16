<?php

namespace Tests\Feature;

use App\Domain\Events\EventSigner;
use App\Domain\Events\OutboxDispatcher;
use App\Domain\Events\OutboxRecorder;
use App\Http\Middleware\TrackMemberActivity;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\DepositService;
use App\Services\WithdrawalService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Bonus\CreatesMoneySchema;
use Tests\TestCase;

/**
 * Transactional outbox + push dispatcher (sqlite in-memory เท่านั้น — ไม่มีข้อมูลจริง ไม่มีการส่งจริง: Http::fake)
 */
class DomainOutboxTest extends TestCase
{
    use CreatesMoneySchema;

    private const URL = 'https://admin-api.test/internal/events';
    private const SECRET = 'test-outbox-secret-0123456789abcdef0123456789';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMoneySchema();
        Schema::table('users', fn ($t) => $t->timestamp('last_activity_at')->nullable());
        (include database_path('migrations/2026_11_14_000001_create_domain_outbox_events_table.php'))->up();
        (include database_path('migrations/2026_11_14_000002_add_marketing_consent_to_users_table.php'))->up();
        config([
            'logging.default' => 'null',
            'domain_events.enabled' => true,
            'domain_events.url' => self::URL,
            'domain_events.secret' => self::SECRET,
        ]);
        OutboxRecorder::flushState();
        Http::preventStrayRequests(); // safety net: nothing leaves the test

        $this->user = User::create([
            'username' => 'crm_user', 'phone' => '0800000009', 'password' => 'x',
            'bank_code' => 'SCB', 'bank_account' => '1234567890', 'bank_name' => 'Test User',
        ]);
        Wallet::create(['user_id' => $this->user->id, 'balance' => 1000]);
    }

    private function events(?string $type = null)
    {
        $q = DB::table('domain_outbox_events')->orderBy('id');
        if ($type) {
            $q->where('event_type', $type);
        }
        return $q->get();
    }

    private function deposit(float $amount, string $status = 'pending'): Deposit
    {
        return Deposit::create([
            'user_id' => $this->user->id, 'reference_id' => 'DEP-' . uniqid('', true), 'amount' => $amount,
            'channel' => 'bank_transfer', 'status' => $status,
        ]);
    }

    private function withdrawal(float $amount): Withdrawal
    {
        return Withdrawal::create([
            'user_id' => $this->user->id, 'reference_id' => 'WD-' . uniqid('', true), 'amount' => $amount,
            'to_bank' => 'SCB', 'to_account' => '1234567890', 'to_name' => 'Test User', 'status' => 'pending',
            'balance_before' => $amount, 'balance_after' => 0,
        ]);
    }

    // ---------------------------------------------------------------- recording

    public function test_registration_records_member_registered_without_secrets(): void
    {
        $e = $this->events('member.registered')->first();
        $this->assertNotNull($e);
        $p = json_decode($e->payload, true);
        $this->assertSame($this->user->id, $p['member_id']);
        $this->assertSame('0800000009', $p['phone']);
        $this->assertArrayNotHasKey('password', $p);
        $this->assertArrayNotHasKey('bank_account', $p);
        $this->assertSame('member', $e->aggregate_type);
        $this->assertSame('pending', $e->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $e->event_id);
    }

    public function test_disabled_records_nothing(): void
    {
        config(['domain_events.enabled' => false]);
        DB::table('domain_outbox_events')->delete();
        app(DepositService::class)->approve($this->deposit(300), 1);
        $this->assertCount(0, $this->events());
    }

    public function test_deposit_approval_records_event_with_minimal_payload(): void
    {
        $d = $this->deposit(500);
        app(DepositService::class)->approve($d, 1);

        $e = $this->events('deposit.approved');
        $this->assertCount(1, $e);
        $p = json_decode($e[0]->payload, true);
        $this->assertSame(['deposit_id', 'member_id', 'amount', 'approved_at'], array_keys($p));
        $this->assertSame('500.00', $p['amount']);
        $this->assertSame((string) $this->user->id, $e[0]->aggregate_id);
        $this->assertSame(1500.0, (float) Wallet::where('user_id', $this->user->id)->value('balance')); // business logic unchanged
    }

    public function test_deposit_created_already_approved_records_event(): void
    {
        $this->deposit(250, 'approved'); // path used by unmatched-deposit approval
        $this->assertCount(1, $this->events('deposit.approved'));
    }

    public function test_rolled_back_operation_emits_nothing(): void
    {
        $d = $this->deposit(700);
        try {
            DB::transaction(function () use ($d) {
                $d->update(['status' => 'approved', 'approved_at' => now()]);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertCount(0, $this->events('deposit.approved'));
        $this->assertSame('pending', $d->fresh()->status);
    }

    public function test_withdrawal_approve_and_reject_events(): void
    {
        app(WithdrawalService::class)->approve($this->withdrawal(100), 1);
        app(WithdrawalService::class)->reject($this->withdrawal(200), 1, 'test');
        $this->assertCount(1, $this->events('withdrawal.approved'));
        $r = $this->events('withdrawal.rejected');
        $this->assertCount(1, $r);
        $this->assertSame('200.00', json_decode($r[0]->payload, true)['amount']);
    }

    public function test_status_and_consent_changes(): void
    {
        $this->user->refresh()->update(['status' => 'banned']);
        $this->user->forceFill(['marketing_sms_consent_at' => now()])->save();
        $this->user->forceFill(['marketing_opted_out_at' => now()])->save();
        $s = json_decode($this->events('member.status_changed')[0]->payload, true);
        $this->assertSame(['banned', 'active'], [$s['status'], $s['previous_status']]);
        $this->assertCount(1, $this->events('member.marketing_consent_updated'));
        $this->assertCount(1, $this->events('member.opted_out'));
    }

    public function test_outbox_failure_never_blocks_a_deposit(): void
    {
        $this->assertTrue(app(OutboxRecorder::class)->enabled()); // table check cached = true
        Schema::drop('domain_outbox_events');                      // simulate a broken outbox
        $d = app(DepositService::class)->approve($this->deposit(400), 1);
        $this->assertSame('approved', $d->status);
        $this->assertSame(1400.0, (float) Wallet::where('user_id', $this->user->id)->value('balance'));
    }

    // ---------------------------------------------------------------- dispatcher

    /** admin-api double; $this->nestMode switches behaviour between calls (Http::fake stubs cannot be replaced) */
    private string $nestMode = 'stored';
    private bool $nestFaked = false;

    private function fakeNest(string $status = 'stored'): void
    {
        $this->nestMode = $status;
        if ($this->nestFaked) {
            return;
        }
        $this->nestFaked = true;
        Http::fake([self::URL => function (HttpRequest $req) {
            if ($this->nestMode === 'offline') {
                throw new ConnectionException('connection refused');
            }
            $ids = array_column($req->data()['events'], 'event_id');
            return Http::response(['results' => array_map(fn ($id) => ['event_id' => $id, 'status' => $this->nestMode], $ids)], 200);
        }]);
    }

    public function test_dispatch_signs_and_marks_delivered(): void
    {
        $this->fakeNest();
        $r = app(OutboxDispatcher::class)->dispatchBatch();
        $this->assertSame(1, $r['delivered']);
        $this->assertSame('delivered', $this->events()[0]->status);

        Http::assertSent(function (HttpRequest $req) {
            $ts = (int) $req->header('X-Event-Timestamp')[0];
            $nonce = $req->header('X-Event-Nonce')[0];
            $expected = EventSigner::sign(self::SECRET, $ts, $nonce, $req->body());
            return $req->url() === self::URL
                && hash_equals($expected, $req->header('X-Event-Signature')[0])
                && !str_contains($req->body(), self::SECRET)
                && isset($req->data()['outbox_health']['pending']);
        });
    }

    public function test_duplicate_ack_counts_as_delivered(): void
    {
        $this->fakeNest('duplicate');
        app(OutboxDispatcher::class)->dispatchBatch();
        $this->assertSame('delivered', $this->events()[0]->status);
    }

    public function test_nest_offline_keeps_event_with_backoff(): void
    {
        $this->fakeNest('offline');
        $r = app(OutboxDispatcher::class)->dispatchBatch();
        $this->assertSame(1, $r['failed']);
        $e = $this->events()[0];
        $this->assertSame('failed', $e->status);
        $this->assertSame(1, (int) $e->attempts);
        $this->assertStringContainsString('connection refused', $e->last_error);
        $this->assertTrue(Carbon::parse($e->next_attempt_at)->isFuture());
        $this->assertNull($e->lock_token);

        // not retried before the backoff time
        $this->assertSame(0, app(OutboxDispatcher::class)->dispatchBatch()['claimed']);

        // later: NestJS is back → delivered
        DB::table('domain_outbox_events')->update(['next_attempt_at' => now()->subSecond()]);
        $this->fakeNest();
        $this->assertSame(1, app(OutboxDispatcher::class)->dispatchBatch()['delivered']);
    }

    public function test_http_500_and_missing_results_are_retried(): void
    {
        Http::fake([self::URL => Http::response('oops', 500)]);
        app(OutboxDispatcher::class)->dispatchBatch();
        $this->assertSame('failed', $this->events()[0]->status);
    }

    public function test_rejected_event_becomes_dead_and_can_be_replayed(): void
    {
        $this->fakeNest('rejected');
        app(OutboxDispatcher::class)->dispatchBatch();
        $this->assertSame('dead', $this->events()[0]->status);

        Artisan::call('domain-events:replay', ['--status' => 'dead']);
        $this->assertSame('pending', $this->events()[0]->status);
        $this->fakeNest();
        app(OutboxDispatcher::class)->dispatchBatch();
        $this->assertSame('delivered', $this->events()[0]->status);
    }

    public function test_max_attempts_marks_dead(): void
    {
        config(['domain_events.max_attempts' => 2]);
        DB::table('domain_outbox_events')->update(['attempts' => 1]);
        Http::fake([self::URL => Http::response('down', 503)]);
        app(OutboxDispatcher::class)->dispatchBatch();
        $this->assertSame('dead', $this->events()[0]->status);
    }

    public function test_lease_prevents_two_workers_taking_the_same_event(): void
    {
        $d = app(OutboxDispatcher::class);
        $first = $d->claim(10);
        $second = $d->claim(10);
        $this->assertCount(1, $first);
        $this->assertCount(0, $second);

        // lease expired (worker crashed) → claimable again
        DB::table('domain_outbox_events')->update(['locked_until' => now()->subSecond()]);
        $this->assertCount(1, $d->claim(10));
    }

    public function test_per_member_order_is_preserved(): void
    {
        $other = User::create(['username' => 'other', 'phone' => '0800000010', 'password' => 'x']);
        app(DepositService::class)->approve($this->deposit(100), 1); // 2nd event of crm_user
        // first event of crm_user is failing (waiting for backoff)
        DB::table('domain_outbox_events')->where('event_type', 'member.registered')->where('aggregate_id', (string) $this->user->id)
            ->update(['status' => 'failed', 'next_attempt_at' => now()->addMinutes(5)]);

        $claimed = collect(app(OutboxDispatcher::class)->claim(10));
        $this->assertSame([(string) $other->id], $claimed->pluck('aggregate_id')->all()); // crm_user's deposit waits
    }

    public function test_backoff_grows_and_is_capped(): void
    {
        config(['domain_events.backoff_base_seconds' => 5, 'domain_events.backoff_max_seconds' => 3600]);
        $this->assertSame(5, OutboxDispatcher::backoffSeconds(1, 1.0));
        $this->assertSame(40, OutboxDispatcher::backoffSeconds(4, 1.0));
        $this->assertSame(3600, OutboxDispatcher::backoffSeconds(30, 1.0));
        $this->assertSame(3, OutboxDispatcher::backoffSeconds(1, 0.0)); // jitter lower bound 50%
    }

    public function test_dispatch_does_nothing_when_not_configured(): void
    {
        config(['domain_events.secret' => 'short']);
        $this->assertSame('not_configured', app(OutboxDispatcher::class)->dispatchBatch()['skipped']);
        $this->assertSame('pending', $this->events()[0]->status);
    }

    // ---------------------------------------------------------------- activity

    public function test_activity_is_throttled_and_emits_only_meaningful_changes(): void
    {
        Cache::flush();
        DB::table('domain_outbox_events')->delete();
        $now = Carbon::parse('2026-11-14 10:00:00');

        $this->assertTrue(TrackMemberActivity::touch($this->user->id, $now));    // first ever → event
        $this->assertFalse(TrackMemberActivity::touch($this->user->id, $now));   // throttled
        $this->assertCount(1, $this->events('member.activity_updated'));

        Cache::flush();
        $this->assertTrue(TrackMemberActivity::touch($this->user->id, $now->copy()->addMinutes(30))); // written, recent → no event
        $this->assertCount(1, $this->events('member.activity_updated'));

        Cache::flush();
        $this->assertTrue(TrackMemberActivity::touch($this->user->id, $now->copy()->addHours(8))); // returned after a gap → event
        $this->assertCount(2, $this->events('member.activity_updated'));
    }

    // ---------------------------------------------------------------- initial import

    public function test_export_is_resumable_after_interruption(): void
    {
        foreach (range(1, 4) as $i) {
            User::create(['username' => "imp{$i}", 'phone' => "08100000{$i}0", 'password' => 'x']);
        }
        $calls = 0;
        Http::fake([self::URL . '/import' => function () use (&$calls) {
            $calls++;
            return $calls === 2 ? Http::response('down', 503) : Http::response(['stored' => 2, 'unchanged' => 0], 200);
        }]);

        $this->assertSame(1, Artisan::call('crm:export-members', ['--batch' => 2]));  // fails on the 2nd batch
        $run = DB::table('domain_export_runs')->where('name', 'crm-initial')->first();
        $this->assertSame('failed', $run->status);
        $this->assertSame(2, (int) $run->sent);
        $lastId = (int) $run->last_id;

        $this->assertSame(0, Artisan::call('crm:export-members', ['--batch' => 2])); // resumes, finishes
        $run = DB::table('domain_export_runs')->where('name', 'crm-initial')->first();
        $this->assertSame('completed', $run->status);
        $this->assertSame(5, (int) $run->sent);

        $sentIds = [];
        Http::assertSent(function (HttpRequest $req) use (&$sentIds) {
            foreach ($req->data()['members'] ?? [] as $m) {
                $sentIds[] = $m['member_id'];
            }
            return true;
        });
        $this->assertGreaterThan(0, $lastId);
        $this->assertSame(User::orderBy('id')->pluck('id')->all(), array_values(array_unique($sentIds)));
    }
}
