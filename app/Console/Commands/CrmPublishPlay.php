<?php

namespace App\Console\Commands;

use App\Domain\Events\OutboxRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "ตามลูกค้าด้วยการ์ดเกมที่เล่น": tells the CRM when each member last played and which games they play most.
 * Laravel outbox → signed HTTPS push → CRM (no polling from the LINE VPS, no access to this database from there).
 *
 * Cheap on the game DB (checked with EXPLAIN on production):
 *  - last play per member = loose index scan on game_logs(user_id, created_at) (~20 ms for all members)
 *  - games of one member  = newest N bets via gl_user_action_created (index range, LIMIT)
 * Only members who played since the last run are looked at; an event is recorded when their game list changed
 * or every CRM_PLAY_EVENT_MINUTES. --since=30d sends everyone who played in the last 30 days (first fill).
 */
class CrmPublishPlay extends Command
{
    public const EVENT = 'member.played';
    private const CHECKPOINT = 'crm:play:checkpoint';

    protected $signature = 'crm:publish-play {--since= : first fill, e.g. 30d or 12h (default: since the last run)} {--sleep-ms=20 : pause between members}';
    protected $description = 'Publish members\' last play time + most played games to the CRM (only members who played since the last run)';

    public function handle(OutboxRecorder $outbox): int
    {
        if (! $outbox->enabled()) {
            $this->line('domain events disabled — nothing sent');

            return self::SUCCESS;
        }
        $runStarted = now();
        $since = $this->since();
        $games = max(1, min(9, (int) config('domain_events.play.games', 5)));
        $scan = max(20, min(2000, (int) config('domain_events.play.scan_bets', 300)));
        $every = max(1, (int) config('domain_events.play.event_every_minutes', 360)) * 60;
        $sleep = max(0, min(1000, (int) $this->option('sleep-ms'))) * 1000;

        $players = DB::table('game_logs')
            ->selectRaw('user_id, MAX(created_at) AS last_played_at')
            ->groupBy('user_id')
            ->havingRaw('MAX(created_at) > ?', [$since])
            ->get();

        $sent = 0;
        foreach ($players as $p) {
            $list = $this->topGames((int) $p->user_id, $scan, $games);
            $key = "crm:play:last:{$p->user_id}";
            $sig = md5(json_encode(array_column($list, 'game_code')));
            $prev = Cache::get($key);
            if (is_array($prev) && ($prev['sig'] ?? null) === $sig && now()->getTimestamp() - (int) ($prev['at'] ?? 0) < $every) {
                continue; // same games, recently reported
            }
            $id = $outbox->record(self::EVENT, 'member', (int) $p->user_id, [
                'member_id'      => (int) $p->user_id,
                'last_played_at' => \Illuminate\Support\Carbon::parse($p->last_played_at)->toIso8601String(),
                'games'          => $list,
            ]);
            if ($id !== null) {
                Cache::put($key, ['sig' => $sig, 'at' => now()->getTimestamp()], now()->addDays(30));
                $sent++;
            }
            if ($sleep) {
                usleep($sleep);
            }
        }
        Cache::forever(self::CHECKPOINT, $runStarted->copy()->subMinute()->toIso8601String()); // 1 min overlap: late inserts
        $this->line("players={$players->count()} published={$sent}");

        return self::SUCCESS;
    }

    private function since(): \Illuminate\Support\Carbon
    {
        $opt = (string) $this->option('since');
        if (preg_match('/^(\d{1,3})([dh])$/', $opt, $m)) {
            return $m[2] === 'd' ? now()->subDays((int) $m[1]) : now()->subHours((int) $m[1]);
        }
        $cp = Cache::get(self::CHECKPOINT);

        return $cp ? \Illuminate\Support\Carbon::parse($cp) : now()->subMinutes(15);
    }

    /** most played (by number of bets among the newest $scan bets) active games, with name + picture */
    public function topGames(int $userId, int $scan, int $limit): array
    {
        $recent = DB::table('game_logs')
            ->select('provider', 'game_id', 'created_at')
            ->where('user_id', $userId)->where('action', 'bet')
            ->orderByDesc('created_at')->limit($scan)->get();
        $count = [];
        foreach ($recent as $r) {
            $k = $r->provider."\0".$r->game_id;
            $count[$k] ??= ['provider' => $r->provider, 'game_id' => $r->game_id, 'n' => 0, 'last' => (string) $r->created_at];
            $count[$k]['n']++;
        }
        uasort($count, fn ($a, $b) => [$b['n'], $b['last']] <=> [$a['n'], $a['last']]);
        $out = [];
        foreach ($count as $c) {
            $g = DB::table('games')->where('product_id', $c['provider'])->where('game_code', $c['game_id'])
                ->first(['product_id', 'game_code', 'game_name', 'game_name_th', 'image_url', 'is_active']);
            if (! $g || ! $g->is_active) {
                continue; // never suggest a game that is switched off
            }
            $out[] = [
                'product_id' => (string) $g->product_id,
                'game_code'  => (string) $g->game_code,
                'name'       => mb_substr((string) ($g->game_name_th ?: $g->game_name ?: $g->game_code), 0, 100),
                'image_url'  => preg_match('#^https://#', (string) $g->image_url) ? mb_substr((string) $g->image_url, 0, 500) : null,
                'bets'       => $c['n'],
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
