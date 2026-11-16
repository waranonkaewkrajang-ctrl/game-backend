<?php

namespace App\Http\Middleware;

use App\Domain\Events\DomainEvents;
use App\Domain\Events\OutboxRecorder;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * บันทึก users.last_activity_at ของสมาชิกที่ล็อกอิน (ใหม่ — ไม่กระทบคำตอบของ request)
 *
 * - ทำใน terminate() = หลังส่ง response แล้ว
 * - throttle: เขียน DB ไม่เกิน 1 ครั้ง / write_every_minutes / คน (Cache::add)
 * - ส่ง event member.activity_updated เฉพาะเมื่อห่างจากกิจกรรมก่อนหน้าเกิน event_every_minutes
 *   (เช่น "กลับมาเล่นหลังหายไป") → ไม่ท่วมระบบด้วย event ทุก request
 * - ผิดพลาดอะไรก็ตาม = log แล้วจบ (ห้ามกระทบการใช้งานของลูกค้า)
 */
class TrackMemberActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $user = $request->user();
            if (!$user instanceof User) {
                return;
            }
            self::touch((int) $user->id);
        } catch (\Throwable $e) {
            Log::warning('[MemberActivity] skipped: ' . $e->getMessage());
        }
    }

    /** @return bool true when the row was written */
    public static function touch(int $userId, ?Carbon $now = null): bool
    {
        $now ??= now();
        $writeEvery = max(1, (int) config('domain_events.activity.write_every_minutes', 5));
        // TTL เป็น "ระยะเวลา" (วินาที) ไม่ใช่เวลาปลายทาง — ถ้าส่ง $now ย้อนหลังมา เวลาปลายทางจะอยู่ในอดีตและ cache ไม่ยอมเก็บ
        if (!Cache::add("member-activity:{$userId}", 1, $writeEvery * 60)) {
            return false; // เขียนไปไม่นานนี้แล้ว
        }

        return (bool) DB::transaction(function () use ($userId, $now) {
            $previous = DB::table('users')->where('id', $userId)->value('last_activity_at');
            $updated = DB::table('users')->where('id', $userId)->update(['last_activity_at' => $now]);
            if (!$updated) {
                return false;
            }
            $eventEvery = max(1, (int) config('domain_events.activity.event_every_minutes', 360));
            if (!$previous || Carbon::parse($previous)->lt($now->copy()->subMinutes($eventEvery))) {
                app(OutboxRecorder::class)->record(DomainEvents::MEMBER_ACTIVITY_UPDATED, 'member', $userId, [
                    'member_id'                 => $userId,
                    'last_activity_at'          => $now->toIso8601String(),
                    'previous_last_activity_at' => DomainEvents::iso($previous),
                ], $now);
            }
            return true;
        });
    }
}
