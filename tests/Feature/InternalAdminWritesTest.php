<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Bonus\CreatesMoneySchema;
use Tests\TestCase;

/**
 * Write path of the internal admin API (unified admin → Laravel).
 * sqlite in-memory only — no real money, no real database.
 *
 * Proves: kill switch, real-admin identity, Idempotency-Key replay (no double credit), conflicting/in-flight keys,
 * existing money locking still applies, secrets never leave via settings, outcome lookup after a timeout.
 */
class InternalAdminWritesTest extends TestCase
{
    use CreatesMoneySchema;

    private const TOKEN = 'test-internal-admin-token-0123456789abcdef0123456789';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMoneySchema();
        Schema::create('admins', function (Blueprint $t) {
            $t->id();
            $t->string('username')->unique();
            $t->string('password');
            $t->string('two_factor_secret')->nullable();
            $t->boolean('two_factor_enabled')->default(false);
            $t->string('name')->nullable();
            $t->string('role')->default('admin');
            $t->text('permissions')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        (require base_path('database/migrations/2026_11_16_000001_create_internal_admin_operations_table.php'))->up();

        Http::fake();
        config([
            'logging.default' => 'null',
            'services.internal_admin.token_hashes' => hash('sha256', self::TOKEN),
            'services.internal_admin.allowed_ips' => '',
            'services.internal_admin.writes_enabled' => true,
        ]);

        DB::table('admins')->insert([
            ['id' => 7, 'username' => 'game_admin', 'password' => 'x', 'name' => 'Game Admin', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 8, 'username' => 'old_admin', 'password' => 'x', 'name' => 'Disabled', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->user = User::create([
            'username' => 'member1', 'phone' => '0800000009', 'password' => 'x',
            'bank_code' => 'SCB', 'bank_account' => '1234567890', 'bank_name' => 'Member One',
        ]);
        Wallet::create(['user_id' => $this->user->id, 'balance' => 0]);
        Setting::create(['key' => 'default_turnover_multiplier', 'value' => '3']);
    }

    private function headers(?string $key = 'key-0000000000000001', ?string $adminId = '7'): array
    {
        $h = ['Authorization' => 'Bearer '.self::TOKEN, 'Accept' => 'application/json', 'X-Acting-Admin' => '3:staff01'];
        if ($key !== null) {
            $h['Idempotency-Key'] = $key;
        }
        if ($adminId !== null) {
            $h['X-Acting-Admin-Id'] = $adminId;
        }
        return $h;
    }

    private function deposit(float $amount): Deposit
    {
        return Deposit::create([
            'user_id' => $this->user->id, 'reference_id' => 'DEP-'.uniqid('', true), 'amount' => $amount,
            'channel' => 'bank_transfer', 'status' => 'pending',
        ]);
    }

    private function balance(): string
    {
        return (string) Wallet::where('user_id', $this->user->id)->value('balance');
    }

    public function test_writes_are_off_unless_enabled(): void
    {
        config(['services.internal_admin.writes_enabled' => false]);
        $d = $this->deposit(500);
        $this->postJson("/api/internal/admin/deposits/{$d->id}/approve", [], $this->headers())->assertStatus(403);
        $this->assertSame('pending', $d->fresh()->status);
        $this->assertSame(0, DB::table('internal_admin_operations')->count());
    }

    public function test_requires_service_token_and_a_linked_active_game_admin(): void
    {
        $d = $this->deposit(500);
        $url = "/api/internal/admin/deposits/{$d->id}/approve";
        $this->postJson($url, [], ['Accept' => 'application/json', 'X-Acting-Admin-Id' => '7', 'Idempotency-Key' => 'key-0000000000000001'])->assertStatus(401);
        $this->postJson($url, [], $this->headers('key-0000000000000001', null))->assertStatus(403);   // not linked
        $this->postJson($url, [], $this->headers('key-0000000000000001', 'abc'))->assertStatus(403);
        $this->postJson($url, [], $this->headers('key-0000000000000001', '8'))->assertStatus(403);    // disabled admin
        $this->postJson($url, [], $this->headers('key-0000000000000001', '99'))->assertStatus(403);   // unknown admin
        $this->postJson($url, [], $this->headers(null))->assertStatus(400);                             // no key
        $this->postJson($url, [], $this->headers('short'))->assertStatus(400);
        $this->assertSame('pending', $d->fresh()->status);
        $this->assertSame('0.00', $this->balance());
    }

    public function test_approve_deposit_records_the_game_admin_and_replay_does_not_credit_twice(): void
    {
        $d = $this->deposit(500);
        $url = "/api/internal/admin/deposits/{$d->id}/approve";

        $first = $this->postJson($url, [], $this->headers('approve-dep-000000001'));
        $first->assertStatus(200)->assertJsonPath('status', 'success');
        $this->assertSame('approved', $d->fresh()->status);
        $this->assertSame(7, (int) $d->fresh()->approved_by);
        $this->assertSame('500.00', $this->balance());

        // network retry with the SAME key → stored answer, action not run again
        $replay = $this->postJson($url, [], $this->headers('approve-dep-000000001'));
        $replay->assertStatus(200)->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('message'), $replay->json('message'));
        $this->assertSame('500.00', $this->balance());
        $this->assertSame(1, Transaction::count());

        // a NEW key (second click / another admin) → existing row lock + status check refuses
        $this->postJson($url, [], $this->headers('approve-dep-000000002'))->assertStatus(400)
            ->assertJsonPath('message', 'รายการนี้ถูกดำเนินการแล้ว');
        $this->assertSame('500.00', $this->balance());
        $this->assertSame(1, Transaction::count());
    }

    public function test_adjust_balance_same_key_applies_once_and_key_reuse_for_other_request_is_refused(): void
    {
        $url = "/api/internal/admin/users/{$this->user->id}/adjust";
        $body = ['amount' => 100, 'description' => 'ปรับยอดทดสอบ'];

        $this->postJson($url, $body, $this->headers('adjust-000000000001'))->assertStatus(200);
        $this->postJson($url, $body, $this->headers('adjust-000000000001'))->assertStatus(200)->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame('100.00', $this->balance());

        $this->postJson($url, ['amount' => 999, 'description' => 'อื่น'], $this->headers('adjust-000000000001'))->assertStatus(422);
        $this->assertSame('100.00', $this->balance());
    }

    public function test_in_flight_key_returns_409(): void
    {
        $url = "/api/internal/admin/users/{$this->user->id}/adjust";
        $body = ['amount' => 50, 'description' => 'x'];
        $hash = \App\Http\Middleware\InternalIdempotency::requestHash(
            \Illuminate\Http\Request::create($url, 'POST', $body), 7
        );
        DB::table('internal_admin_operations')->insert([
            'idempotency_key' => 'inflight-0000000001', 'admin_id' => 7, 'method' => 'POST', 'path' => ltrim($url, '/'),
            'request_hash' => $hash, 'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson($url, $body, $this->headers('inflight-0000000001'))->assertStatus(409);
        $this->assertSame('0.00', $this->balance());
    }

    public function test_operation_outcome_lookup(): void
    {
        $d = $this->deposit(300);
        $this->postJson("/api/internal/admin/deposits/{$d->id}/approve", [], $this->headers('lookup-00000000001'))->assertStatus(200);
        $auth = ['Authorization' => 'Bearer '.self::TOKEN, 'Accept' => 'application/json'];
        $this->getJson('/api/internal/admin/operations/lookup-00000000001', $auth)
            ->assertStatus(200)->assertJsonPath('state', 'done')->assertJsonPath('response_status', 200);
        $this->getJson('/api/internal/admin/operations/unknown-0000000001', $auth)->assertStatus(404);
    }

    public function test_settings_never_expose_secrets_and_masked_value_does_not_overwrite(): void
    {
        Setting::create(['key' => 'telegram_bot_token', 'value' => '123456:REAL-SECRET']);
        Setting::create(['key' => 'min_deposit', 'value' => '100']);
        $auth = ['Authorization' => 'Bearer '.self::TOKEN, 'Accept' => 'application/json'];

        $res = $this->getJson('/api/internal/admin/settings', $auth)->assertStatus(200);
        $this->assertSame('********', $res->json('telegram_bot_token'));
        $this->assertSame('100', $res->json('min_deposit'));
        $this->assertStringNotContainsString('REAL-SECRET', $res->getContent());

        $this->postJson('/api/internal/admin/settings', ['telegram_bot_token' => '********', 'min_deposit' => '200'], $this->headers('settings-0000000001'))
            ->assertStatus(200);
        $this->assertSame('123456:REAL-SECRET', Setting::where('key', 'telegram_bot_token')->value('value'));
        $this->assertSame('200', Setting::where('key', 'min_deposit')->value('value'));
    }

    public function test_credential_and_bot_only_routes_are_not_exposed(): void
    {
        foreach (['api-tokens', 'bank-incoming', 'bank-incoming/resume', 'spin-wheel/give-tickets', 'truewallet/1/match'] as $p) {
            $status = $this->postJson("/api/internal/admin/{$p}", [], $this->headers('route-check-000000001'))->getStatusCode();
            $this->assertContains($status, [404, 405], $p);
        }
        $this->getJson('/api/internal/admin/marketing/export', ['Authorization' => 'Bearer '.self::TOKEN, 'Accept' => 'application/json'])->assertStatus(404);
    }

    public function test_prune_keeps_processing_records_and_recent_ones(): void
    {
        $row = fn ($key, $status, $age) => [
            'idempotency_key' => $key, 'admin_id' => 7, 'method' => 'POST', 'path' => 'x', 'request_hash' => str_repeat('a', 64),
            'status' => $status, 'created_at' => now()->subDays($age), 'updated_at' => now()->subDays($age),
        ];
        DB::table('internal_admin_operations')->insert([
            $row('old-done-000000001', 'done', 40),
            $row('new-done-000000001', 'done', 1),
            $row('old-proc-000000001', 'processing', 40),
        ]);
        $this->artisan('internal-admin:prune')->assertExitCode(0);
        $this->assertSame(['new-done-000000001', 'old-proc-000000001'],
            DB::table('internal_admin_operations')->orderBy('idempotency_key')->pluck('idempotency_key')->all());
    }

    public function test_every_internal_route_points_to_an_existing_controller_method(): void
    {
        $count = 0;
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/internal/admin')) {
                continue;
            }
            $action = $route->getActionName();
            if ($action === 'Closure') {
                continue;
            }
            [$class, $method] = array_pad(explode('@', $action), 2, '__invoke');
            $this->assertTrue(class_exists($class), "missing class {$class} for {$route->uri()}");
            $this->assertTrue(method_exists($class, $method), "missing {$class}@{$method} for {$route->uri()}");
            $count++;
        }
        $this->assertGreaterThan(90, $count);
    }
}
