<?php

namespace App\Domain\Events;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ส่ง event จาก outbox ไป admin-api (HTTPS + HMAC) — ทำงานนอก transaction การเงินเสมอ
 *
 * - lease: จองแถวด้วย UPDATE ... WHERE (locked_until IS NULL OR < now) → หลาย worker ไม่ส่งแถวเดียวกันพร้อมกัน
 * - ลำดับต่อสมาชิก: ไม่ส่ง event ที่มี event ก่อนหน้าของ aggregate เดียวกันยังค้าง (failed/กำลังส่ง/รอ backoff)
 * - ล้มเหลว: บันทึก last_error + backoff แบบ exponential + jitter, เกิน max_attempts = dead (สั่ง replay ได้)
 * - admin-api ตอบ stored/duplicate = delivered (ยืนยันหลังบันทึกลง Postgres แล้วเท่านั้น), rejected = dead
 */
class OutboxDispatcher
{
    public function __construct(private EventSigner $signer) {}

    /** @return array{claimed:int, delivered:int, failed:int, dead:int, skipped?:string} */
    public function dispatchBatch(?int $limit = null): array
    {
        $out = ['claimed' => 0, 'delivered' => 0, 'failed' => 0, 'dead' => 0];
        if (!app(OutboxRecorder::class)->enabled()) {
            return $out + ['skipped' => 'disabled'];
        }
        if (!$this->signer->configured()) {
            return $out + ['skipped' => 'not_configured'];
        }

        $rows = $this->claim($limit ?? max(1, min(500, (int) config('domain_events.batch_size', 50))));
        $out['claimed'] = count($rows);
        if (!$rows) {
            return $out;
        }

        $body = json_encode([
            'source'        => (string) config('domain_events.source', 'game-backend'),
            'sent_at'       => now()->toIso8601String(),
            'outbox_health' => $this->health(),
            'events'        => array_map(fn ($r) => [
                'event_id'          => $r->event_id,
                'event_type'        => $r->event_type,
                'aggregate_type'    => $r->aggregate_type,
                'aggregate_id'      => $r->aggregate_id,
                'aggregate_version' => (int) $r->aggregate_version,
                'schema_version'    => (int) $r->schema_version,
                'occurred_at'       => Carbon::parse($r->occurred_at)->toIso8601String(),
                'payload'           => json_decode($r->payload, true),
            ], $rows),
        ], JSON_UNESCAPED_UNICODE);

        $results = null;
        $error = null;
        try {
            $res = Http::withHeaders($this->signer->headers($body))
                ->withBody($body, 'application/json')
                ->connectTimeout(max(1, (int) config('domain_events.connect_timeout', 3)))
                ->timeout(max(1, (int) config('domain_events.timeout', 10)))
                ->post((string) config('domain_events.url'));
            if ($res->successful() && is_array($res->json('results'))) {
                $results = collect($res->json('results'))->keyBy('event_id');
            } else {
                $error = 'HTTP ' . $res->status() . ' ' . Str::limit((string) $res->body(), 300);
            }
        } catch (\Throwable $e) {
            $error = Str::limit($e->getMessage(), 300);
        }

        foreach ($rows as $r) {
            $result = $results?->get($r->event_id);
            $status = is_array($result) ? ($result['status'] ?? null) : null;
            if (in_array($status, ['stored', 'duplicate'], true)) {
                $this->markDelivered($r->id);
                $out['delivered']++;
            } elseif ($status === 'rejected') {
                $this->markDead($r->id, 'rejected: ' . Str::limit((string) ($result['error'] ?? ''), 300));
                $out['dead']++;
            } else {
                $dead = $this->markFailed($r, $error ?? 'no result for event');
                $out[$dead ? 'dead' : 'failed']++;
            }
        }
        if ($out['failed'] || $out['dead']) {
            Log::warning('[DomainOutbox] dispatch problems', $out + ['error' => $error]);
        }
        return $out;
    }

    /** ส่งต่อเนื่องจนว่างหรือครบจำนวนรอบ (ใช้กับ command / worker) */
    public function drain(int $maxBatches = 20): array
    {
        $total = ['claimed' => 0, 'delivered' => 0, 'failed' => 0, 'dead' => 0];
        for ($i = 0; $i < $maxBatches; $i++) {
            $r = $this->dispatchBatch();
            foreach ($total as $k => $_) {
                $total[$k] += $r[$k];
            }
            if (isset($r['skipped'])) {
                return $total + ['skipped' => $r['skipped']];
            }
            if ($r['claimed'] === 0 || $r['delivered'] === 0) {
                break; // ว่าง หรือปลายทางมีปัญหา → รอรอบถัดไป (backoff ทำงาน)
            }
        }
        return $total;
    }

    /** @return array<int,object> */
    public function claim(int $limit): array
    {
        $now = now();
        $ids = DB::table('domain_outbox_events as e')
            ->whereIn('e.status', ['pending', 'failed'])
            ->where('e.next_attempt_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('e.locked_until')->orWhere('e.locked_until', '<', $now))
            ->whereNotExists(function ($q) use ($now) {
                // event ก่อนหน้าของสมาชิก/รายการเดียวกันที่ยังส่งไม่ได้ตอนนี้ → รอก่อน (รักษาลำดับ)
                $q->from('domain_outbox_events as p')
                    ->whereColumn('p.aggregate_type', 'e.aggregate_type')
                    ->whereColumn('p.aggregate_id', 'e.aggregate_id')
                    ->whereColumn('p.id', '<', 'e.id')
                    ->where(function ($w) use ($now) {
                        $w->where('p.status', 'failed')
                            ->orWhere(fn ($x) => $x->where('p.status', 'pending')->where('p.next_attempt_at', '>', $now))
                            ->orWhere(fn ($x) => $x->whereIn('p.status', ['pending', 'failed'])->where('p.locked_until', '>=', $now));
                    });
            })
            ->orderBy('e.id')
            ->limit($limit)
            ->pluck('e.id')
            ->all();
        if (!$ids) {
            return [];
        }

        $token = (string) Str::uuid();
        DB::table('domain_outbox_events')
            ->whereIn('id', $ids)
            ->whereIn('status', ['pending', 'failed'])
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', $now))
            ->update([
                'locked_until' => $now->copy()->addSeconds(max(10, (int) config('domain_events.lease_seconds', 60))),
                'lock_token'   => $token,
                'updated_at'   => $now,
            ]);

        return DB::table('domain_outbox_events')->where('lock_token', $token)->orderBy('id')->get()->all();
    }

    private function markDelivered(int $id): void
    {
        DB::table('domain_outbox_events')->where('id', $id)->update([
            'status' => 'delivered', 'delivered_at' => now(), 'locked_until' => null, 'lock_token' => null,
            'last_error' => null, 'updated_at' => now(),
        ]);
    }

    private function markDead(int $id, string $error): void
    {
        DB::table('domain_outbox_events')->where('id', $id)->update([
            'status' => 'dead', 'locked_until' => null, 'lock_token' => null, 'last_error' => $error, 'updated_at' => now(),
        ]);
    }

    /** @return bool true when it became dead */
    private function markFailed(object $row, string $error): bool
    {
        $attempts = (int) $row->attempts + 1;
        $dead = $attempts >= max(1, (int) config('domain_events.max_attempts', 25));
        DB::table('domain_outbox_events')->where('id', $row->id)->update([
            'status'          => $dead ? 'dead' : 'failed',
            'attempts'        => $attempts,
            'next_attempt_at' => now()->addSeconds(self::backoffSeconds($attempts)),
            'locked_until'    => null,
            'lock_token'      => null,
            'last_error'      => Str::limit($error, 1000),
            'updated_at'      => now(),
        ]);
        return $dead;
    }

    /** exponential backoff + jitter (50–100%) */
    public static function backoffSeconds(int $attempts, ?float $rand = null): int
    {
        $base = max(1, (int) config('domain_events.backoff_base_seconds', 5));
        $max = max($base, (int) config('domain_events.backoff_max_seconds', 3600));
        $delay = min($max, $base * (2 ** min(20, max(0, $attempts - 1))));
        $rand ??= mt_rand() / mt_getrandmax();
        return max(1, (int) round($delay * (0.5 + 0.5 * $rand)));
    }

    /** สถานะ outbox (ส่งไปพร้อมทุก push → admin-api แสดงสุขภาพได้โดยไม่ต้อง pull) */
    public function health(): array
    {
        $counts = DB::table('domain_outbox_events')
            ->whereIn('status', ['pending', 'failed', 'dead'])
            ->selectRaw('status, COUNT(*) as c, MIN(created_at) as oldest')
            ->groupBy('status')->get()->keyBy('status');
        $oldest = collect(['pending', 'failed'])->map(fn ($s) => $counts[$s]->oldest ?? null)->filter()->min();
        return [
            'pending'           => (int) ($counts['pending']->c ?? 0),
            'failed'            => (int) ($counts['failed']->c ?? 0),
            'dead'              => (int) ($counts['dead']->c ?? 0),
            'oldest_pending_at' => $oldest ? Carbon::parse($oldest)->toIso8601String() : null,
        ];
    }
}
