<?php

namespace Tests\Feature;

use App\Console\Commands\PresencePublish;
use App\Domain\Events\OutboxRecorder;
use App\Http\Controllers\Admin\AdminTopbarController;
use App\Models\User;
use App\Services\AMBService;
use App\Services\CustomerPresence;
use App\Support\CustomerAvatar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Feature\Bonus\CreatesMoneySchema;
use Tests\TestCase;

/**
 * Header "ลูกค้าออนไลน์": presence count, avatar = the website's avatar, presence push event.
 * sqlite in-memory only, nothing is sent (Http::preventStrayRequests).
 */
class CustomerPresenceAvatarTest extends TestCase
{
    use CreatesMoneySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMoneySchema();
        foreach ([
            '2026_07_15_064324_create_personal_access_tokens_table.php',
            '2026_08_12_140000_add_last_activity_at_to_users_table.php',
            '2026_11_14_000001_create_domain_outbox_events_table.php',
            '2026_11_17_000004_add_avatar_to_users_table.php',
        ] as $m) {
            if ($m === '2026_08_12_140000_add_last_activity_at_to_users_table.php' && \Schema::hasColumn('users', 'last_activity_at')) {
                continue;
            }
            (include database_path("migrations/{$m}"))->up();
        }
        if (! \Schema::hasTable('admins')) {
            \Schema::create('admins', function ($t) {
                $t->id();
                $t->string('username');
                $t->timestamps();
            });
        }
        config([
            'logging.default' => 'null',
            'customer.site_url' => 'https://site.test/',
            'customer.avatars.count' => 18,
            'customer.presence.online_minutes' => 5,
            'customer.presence.heartbeat_minutes' => 10,
        ]);
        OutboxRecorder::flushState();
        Http::preventStrayRequests();
        Cache::flush();
    }

    private function member(string $username): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'username' => $username, 'phone' => '08100000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'password' => 'x',
            'bank_code' => 'SCB', 'bank_account' => '12345678'.$n, 'bank_name' => 'Test',
        ]);
    }

    private function token(User $u, ?\DateTimeInterface $lastUsed): void
    {
        $t = $u->createToken('auth-token')->accessToken;
        DB::table('personal_access_tokens')->where('id', $t->id)->update(['last_used_at' => $lastUsed]);
    }

    public function test_default_avatar_is_identical_to_the_website_function(): void
    {
        // computed by the website's own getAvatarPath() (game-client app/profile/utils/avatar.ts) in Node
        $fromWebsite = ['guest' => '13', 'sn01660' => '07', 'a' => '08', 'Trithipphayanipha' => '18', 'สมชาย123' => '08',
            'user_with_a_very_long_name_0123456789abcdefghijklmnopqrstuvwxyz' => '14', '😀emoji' => '18', 'ABCdef999' => '17', 'zz' => '17'];
        foreach ($fromWebsite as $username => $id) {
            $this->assertSame($id, CustomerAvatar::defaultId((string) $username), "username {$username}");
        }
        $this->assertSame(
            ['avatar_id' => '05', 'avatar_selected' => true, 'avatar_url' => 'https://site.test/avatars/05.webp'],
            CustomerAvatar::resolve('05', 'sn01660'),
        );
        $this->assertSame('07', CustomerAvatar::resolve('99', 'sn01660')['avatar_id']); // invalid stored value → default
        $this->assertFalse(CustomerAvatar::resolve(null, 'sn01660')['avatar_selected']);
    }

    public function test_member_saves_the_avatar_picked_on_the_website(): void
    {
        $u = $this->member('pick_me');
        $this->putJson('/api/member/avatar', ['avatar' => '07'])->assertStatus(401);

        Sanctum::actingAs($u);
        $this->putJson('/api/member/avatar', ['avatar' => '07'])->assertOk()->assertJson(['data' => ['avatar' => '07']]);
        $this->assertSame('07', $u->fresh()->avatar);
        $this->putJson('/api/member/avatar', ['avatar' => '19'])->assertStatus(422);
        $this->putJson('/api/member/avatar', ['avatar' => '../x'])->assertStatus(422);
        $this->putJson('/api/member/avatar', [])->assertStatus(422);
        $this->assertSame('07', $u->fresh()->avatar);
        // /auth/me (read by the website) carries it
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.avatar', '07');
        // back to default
        $this->putJson('/api/member/avatar', ['avatar' => null])->assertOk();
        $this->assertNull($u->fresh()->avatar);
    }

    public function test_online_count_is_per_member_with_a_time_window(): void
    {
        $a = $this->member('alice');
        $b = $this->member('bob');
        $c = $this->member('carol');
        $d = $this->member('dave');
        $this->token($a, now()->subSeconds(20));
        $this->token($a, now()->subMinute());          // second device/tab of the same member
        $this->token($b, now()->subMinutes(4));
        $this->token($c, now()->subMinutes(6));        // outside the window
        $this->token($d, null);                        // logged in, never used → not online
        $a->forceFill(['avatar' => '03'])->save();

        $p = app(CustomerPresence::class);
        $this->assertSame(2, $p->onlineCount());
        $rows = $p->onlineMembers();
        $this->assertSame(['alice', 'bob'], array_column($rows, 'username'));
        $this->assertSame('https://site.test/avatars/03.webp', $rows[0]['avatar_url']);
        $this->assertTrue($rows[0]['avatar_selected']);
        $this->assertSame(CustomerAvatar::defaultId('bob'), $rows[1]['avatar_id']);
        $this->assertSame($b->id, $rows[1]['id']);

        // the topbar endpoint uses the same numbers + credit time
        $amb = Mockery::mock(AMBService::class);
        $amb->shouldReceive('getAgentCredit')->once()->andReturn(['code' => 0, 'data' => 67505.98]);
        $res = app(AdminTopbarController::class)->index(Request::create('/'), $amb, $p)->getData(true)['data'];
        $this->assertSame(2, $res['online_users']);
        $this->assertSame(67505.98, $res['agent_credit']);
        $this->assertNotNull($res['agent_credit_at']);
        $this->assertSame(5, $res['online_minutes']);
        $this->assertSame('https://site.test/avatars/03.webp', $res['users'][0]['avatar_url']);
    }

    public function test_credit_failure_is_null_not_a_stale_number(): void
    {
        $amb = Mockery::mock(AMBService::class);
        $amb->shouldReceive('getAgentCredit')->andThrow(new \RuntimeException('down'));
        $res = app(AdminTopbarController::class)->index(Request::create('/'), $amb, app(CustomerPresence::class))->getData(true)['data'];
        $this->assertNull($res['agent_credit']);
        $this->assertNull($res['agent_credit_at']);
    }

    public function test_presence_event_only_when_the_count_changes(): void
    {
        $count = fn () => DB::table('domain_outbox_events')->where('event_type', PresencePublish::EVENT)->count();

        config(['domain_events.enabled' => false]);
        $this->artisan('presence:publish')->assertSuccessful();
        $this->assertSame(0, $count());

        config(['domain_events.enabled' => true]);
        OutboxRecorder::flushState();
        $this->artisan('presence:publish')->assertSuccessful();
        $this->assertSame(1, $count());
        $this->artisan('presence:publish')->assertSuccessful();   // same count → nothing
        $this->assertSame(1, $count());

        $this->token($this->member('erin'), now());
        $this->artisan('presence:publish')->assertSuccessful();   // changed → event
        $this->assertSame(2, $count());
        $row = DB::table('domain_outbox_events')->where('event_type', PresencePublish::EVENT)->orderByDesc('id')->first();
        $this->assertSame(['online' => 1, 'window_minutes' => 5], json_decode($row->payload, true));
        $this->assertSame('presence', $row->aggregate_type);

        $this->travel(11)->minutes();                               // heartbeat even without change
        DB::table('personal_access_tokens')->update(['last_used_at' => now()]);
        $this->artisan('presence:publish')->assertSuccessful();
        $this->assertSame(3, $count());
    }
}
