<?php

namespace App\Console\Commands;

use App\Domain\Events\OutboxRecorder;
use App\Services\CustomerPresence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Pushes the number of members online to the unified admin header (real time, no polling from the LINE VPS):
 * Laravel outbox → signed HTTPS push → NestJS inbox → Socket.IO.
 * Runs every minute from cron; records an event only when the count changed, or every
 * CUSTOMER_PRESENCE_HEARTBEAT_MINUTES so a restarted admin API gets a value. Count only — no member data.
 */
class PresencePublish extends Command
{
    public const EVENT = 'presence.customers_online';

    private const LAST = 'presence:customers_online:last';

    protected $signature = 'presence:publish';

    protected $description = 'Publish the members-online count to the unified admin (only when it changed)';

    public function handle(CustomerPresence $presence, OutboxRecorder $outbox): int
    {
        if (! $outbox->enabled()) {
            $this->line('domain events disabled — nothing sent');
            return self::SUCCESS;
        }
        $count = $presence->onlineCount();
        $last = Cache::get(self::LAST);
        $heartbeat = max(1, (int) config('customer.presence.heartbeat_minutes', 10)) * 60;
        if (is_array($last) && ($last['online'] ?? null) === $count && now()->getTimestamp() - (int) ($last['at'] ?? 0) < $heartbeat) {
            return self::SUCCESS;
        }
        $id = $outbox->record(self::EVENT, 'presence', 'customers', [
            'online'         => $count,
            'window_minutes' => $presence->minutes(),
        ]);
        if ($id !== null) {
            Cache::put(self::LAST, ['online' => $count, 'at' => now()->getTimestamp()], now()->addDay());
        }
        $this->line("online={$count}".($id ? ' published' : ' not recorded'));

        return self::SUCCESS;
    }
}
