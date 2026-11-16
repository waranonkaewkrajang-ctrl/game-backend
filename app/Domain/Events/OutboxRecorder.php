<?php

namespace App\Domain\Events;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * เขียน domain event ลง outbox ด้วย connection/transaction เดียวกับธุรกรรมที่กำลังทำอยู่
 *
 * - ถูกเรียกจาก model observer → ทำงาน "ภายใน" DB::transaction ของ flow เดิม: ถ้าธุรกรรม rollback event ก็หายไปด้วย
 * - ไม่มี network call ใด ๆ ที่นี่ (การส่งออกทำหลัง commit โดย OutboxDispatcher)
 * - ความผิดพลาดของ outbox ต้องไม่ทำให้ฝาก/ถอน/สมัครล้ม: จับ exception แล้ว log (InnoDB rollback แค่ statement นี้)
 *   → event ที่หายจากกรณีนี้ซ่อมได้ด้วยคำสั่ง crm:export-members (ซ่อมข้อมูลสมาชิกแบบสั่งเอง)
 */
class OutboxRecorder
{
    public const SCHEMA_VERSION = 1;

    private static ?bool $tableReady = null;
    private static bool $wakeScheduled = false;

    public function enabled(): bool
    {
        if (!filter_var(config('domain_events.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        if (self::$tableReady === null) {
            try {
                self::$tableReady = Schema::hasTable('domain_outbox_events');
            } catch (\Throwable) {
                self::$tableReady = false;
            }
        }
        return self::$tableReady;
    }

    /** reset per-process caches (tests) */
    public static function flushState(): void
    {
        self::$tableReady = null;
        self::$wakeScheduled = false;
    }

    /** logical clock in microseconds — monotonic per process, comparable across events of one aggregate */
    public static function version(): int
    {
        static $last = 0;
        $now = (int) floor(microtime(true) * 1_000_000);
        $last = $now > $last ? $now : $last + 1;
        return $last;
    }

    public function record(string $eventType, string $aggregateType, string|int $aggregateId, array $payload, ?\DateTimeInterface $occurredAt = null): ?string
    {
        if (!$this->enabled()) {
            return null;
        }
        try {
            $eventId = (string) Str::uuid();
            $now = now();
            DB::table('domain_outbox_events')->insert([
                'event_id'          => $eventId,
                'event_type'        => $eventType,
                'aggregate_type'    => $aggregateType,
                'aggregate_id'      => (string) $aggregateId,
                'aggregate_version' => self::version(),
                'schema_version'    => self::SCHEMA_VERSION,
                'payload'           => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'status'            => 'pending',
                'attempts'          => 0,
                'next_attempt_at'   => $now,
                'occurred_at'       => $occurredAt ?? $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $this->wakeAfterCommit();
            return $eventId;
        } catch (\Throwable $e) {
            Log::error('[DomainOutbox] could not record event (business operation continues)', [
                'type' => $eventType, 'aggregate' => "{$aggregateType}:{$aggregateId}", 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * หลัง commit: ปลุก dispatcher ตอนจบ request (ไม่หน่วงคำตอบของลูกค้า/แอดมิน)
     * ถ้าไม่ได้ทำ (process ตาย ฯลฯ) ตัว schedule domain-events:dispatch ที่สแกน outbox ในเครื่องจะส่งแทน
     */
    private function wakeAfterCommit(): void
    {
        if (self::$wakeScheduled || app()->runningUnitTests()) {
            return;
        }
        self::$wakeScheduled = true;
        DB::afterCommit(function () {
            app()->terminating(function () {
                self::$wakeScheduled = false;
                try {
                    app(OutboxDispatcher::class)->dispatchBatch();
                } catch (\Throwable $e) {
                    Log::warning('[DomainOutbox] wake dispatch failed (will retry from schedule): ' . $e->getMessage());
                }
            });
        });
    }
}
