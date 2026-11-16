<?php

namespace Tests\Feature;

use App\Console\Commands\CrmPublishPlay;
use App\Domain\Events\OutboxRecorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Bonus\CreatesMoneySchema;
use Tests\TestCase;

/** ตามลูกค้าด้วยการ์ดเกมที่เล่น: last play + most played active games → outbox (sqlite in-memory, nothing sent) */
class CrmPublishPlayTest extends TestCase
{
    use CreatesMoneySchema;

    private int $round = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMoneySchema();
        if (! \Schema::hasTable('game_logs')) {
            (include database_path('migrations/2024_01_01_000007_create_game_logs_table.php'))->up();
        }
        if (! \Schema::hasTable('games')) {
            (include database_path('migrations/2024_01_01_000010_create_games_table.php'))->up();
        }
        (include database_path('migrations/2026_11_14_000001_create_domain_outbox_events_table.php'))->up();
        config(['logging.default' => 'null', 'domain_events.enabled' => true, 'domain_events.play.games' => 2,
            'domain_events.play.scan_bets' => 300, 'domain_events.play.event_every_minutes' => 360]);
        OutboxRecorder::flushState();
        Http::preventStrayRequests();
        Cache::flush();
        foreach ([['PGSOFT', 'lucky-neko', 'ลัคกี้เนโกะ', true], ['PGSOFT', 'mahjong', null, true], ['JILI', 'off-game', 'ปิดอยู่', false], ['JILI', 'super-ace', null, true]] as [$p, $c, $th, $on]) {
            DB::table('games')->insert(['product_id' => $p, 'game_code' => $c, 'game_name' => strtoupper($c), 'game_name_th' => $th,
                'image_url' => "https://img.test/{$c}.png", 'is_active' => $on, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function member(int $id): void
    {
        DB::table('users')->insert(['id' => $id, 'username' => "u{$id}", 'phone' => '08'.str_pad((string) $id, 8, '0', STR_PAD_LEFT),
            'password' => 'x', 'bank_code' => 'SCB', 'bank_account' => "1{$id}", 'bank_name' => 'T', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function bet(int $user, string $provider, string $game, $at, string $action = 'bet'): void
    {
        DB::table('game_logs')->insert(['user_id' => $user, 'provider' => $provider, 'game_id' => $game, 'round_id' => 'r'.(++$this->round),
            'action' => $action, 'bet_amount' => 1, 'win_amount' => 0, 'balance_before' => 10, 'balance_after' => 9, 'created_at' => $at, 'updated_at' => $at]);
    }

    private function events(): array
    {
        return DB::table('domain_outbox_events')->where('event_type', CrmPublishPlay::EVENT)->orderBy('id')->get()
            ->map(fn ($r) => json_decode($r->payload, true))->all();
    }

    public function test_most_played_active_games_of_members_who_played(): void
    {
        $this->member(1);
        $this->member(2);
        foreach (range(1, 3) as $i) {
            $this->bet(1, 'PGSOFT', 'mahjong', now()->subMinutes(10 + $i));
        }
        foreach (range(1, 5) as $i) {
            $this->bet(1, 'JILI', 'off-game', now()->subMinutes(5 + $i));   // most played but switched off → skipped
        }
        $this->bet(1, 'PGSOFT', 'lucky-neko', now()->subMinutes(2));
        $this->bet(1, 'PGSOFT', 'lucky-neko', now()->subMinutes(3));
        $this->bet(1, 'JILI', 'super-ace', now()->subMinute());
        $this->bet(1, 'PGSOFT', 'mahjong', now()->subSeconds(30), 'win'); // wins don't count as plays of a game
        $this->bet(2, 'PGSOFT', 'mahjong', now()->subDays(3));          // outside the window

        $this->artisan('crm:publish-play', ['--sleep-ms' => 0])->assertSuccessful();
        $ev = $this->events();
        $this->assertCount(1, $ev);
        $this->assertSame(1, $ev[0]['member_id']);
        $this->assertSame(['mahjong', 'lucky-neko'], array_column($ev[0]['games'], 'game_code'));
        $this->assertSame('ลัคกี้เนโกะ', $ev[0]['games'][1]['name']);
        $this->assertSame('MAHJONG', $ev[0]['games'][0]['name']);         // no Thai name → English name
        $this->assertSame('https://img.test/mahjong.png', $ev[0]['games'][0]['image_url']);
        $this->assertNotEmpty($ev[0]['last_played_at']);

        // nothing new → nothing sent; first fill picks member 2 too
        $this->artisan('crm:publish-play', ['--sleep-ms' => 0])->assertSuccessful();
        $this->assertCount(1, $this->events());
        $this->artisan('crm:publish-play', ['--since' => '7d', '--sleep-ms' => 0])->assertSuccessful();
        $this->assertSame([1, 2], array_column($this->events(), 'member_id')); // member 1 unchanged within 6 h → not repeated
    }

    public function test_disabled_domain_events_send_nothing(): void
    {
        config(['domain_events.enabled' => false]);
        OutboxRecorder::flushState();
        $this->member(1);
        $this->bet(1, 'PGSOFT', 'mahjong', now());
        $this->artisan('crm:publish-play')->assertSuccessful();
        $this->assertSame([], $this->events());
    }
}
