<?php

namespace App\Console\Commands;

use App\Domain\Events\DomainEvents;
use App\Domain\Events\EventSigner;
use App\Domain\Events\OutboxRecorder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * นำเข้าสมาชิกไป CRM ครั้งแรก / ซ่อมข้อมูลแบบสั่งเอง (ไม่ใช่การ sync ตามรอบ)
 *
 * - push เป็นชุด (--batch) ไป {DOMAIN_EVENTS_IMPORT_URL} ด้วย HMAC เดียวกับ outbox
 * - checkpoint อยู่ในตาราง domain_export_runs → ถูกขัดจังหวะแล้วรันใหม่จะทำต่อจาก last_id
 * - แต่ละแถวมี source_version (µs) → ฝั่ง CRM ไม่เขียนทับข้อมูลที่ใหม่กว่าจาก event สด
 * - CRM ไม่เริ่มแคมเปญจากสมาชิกที่นำเข้า (เป็นข้อมูลอ้างอิงเท่านั้น)
 */
class CrmExportMembers extends Command
{
    protected $signature = 'crm:export-members {--batch=500} {--restart : start from the first member again} {--name=crm-initial} {--max-batches=0 : stop after N batches (0 = all)}';
    protected $description = 'One-time / admin-triggered push of member snapshots to the CRM (resumable)';

    public function handle(EventSigner $signer): int
    {
        if (!$signer->configured()) {
            $this->error('ยังไม่ได้ตั้ง DOMAIN_EVENTS_URL / DOMAIN_EVENTS_SECRET (>=32 ตัว)');
            return self::FAILURE;
        }
        $name = Str::limit((string) $this->option('name'), 60, '');
        $batch = max(1, min(1000, (int) $this->option('batch')));
        $url = (string) (config('domain_events.import_url') ?: rtrim((string) config('domain_events.url'), '/') . '/import');

        $run = DB::table('domain_export_runs')->where('name', $name)->first();
        if (!$run || $this->option('restart')) {
            DB::table('domain_export_runs')->updateOrInsert(['name' => $name], [
                'status' => 'running', 'last_id' => 0, 'max_id' => (int) User::withTrashed()->max('id'),
                'sent' => 0, 'duplicates' => 0, 'last_error' => null, 'started_at' => now(), 'finished_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $run = DB::table('domain_export_runs')->where('name', $name)->first();
        } elseif ($run->status === 'completed') {
            $this->info('นำเข้าครบแล้ว (ใช้ --restart เพื่อเริ่มใหม่)');
            return self::SUCCESS;
        }

        $lastId = (int) $run->last_id;
        $batches = 0;
        $maxBatches = max(0, (int) $this->option('max-batches'));
        while (true) {
            $users = User::query()->where('id', '>', $lastId)->orderBy('id')->limit($batch)->get();
            if ($users->isEmpty()) {
                DB::table('domain_export_runs')->where('name', $name)->update(['status' => 'completed', 'finished_at' => now(), 'updated_at' => now()]);
                $this->info("เสร็จ: last_id={$lastId}");
                return self::SUCCESS;
            }

            $deposits = DB::table('deposits')->whereIn('user_id', $users->pluck('id'))->where('status', 'approved')
                ->selectRaw('user_id, COUNT(*) cnt, MIN(approved_at) first_at, MAX(approved_at) last_at')
                ->groupBy('user_id')->get()->keyBy('user_id');

            $members = $users->map(function (User $u) use ($deposits) {
                $d = $deposits[$u->id] ?? null;
                return DomainEvents::memberSnapshot($u) + [
                    'source_version'   => OutboxRecorder::version(),
                    'deposit_count'    => (int) ($d->cnt ?? 0),
                    'first_deposit_at' => DomainEvents::iso($d->first_at ?? null),
                    'last_deposit_at'  => DomainEvents::iso($d->last_at ?? null),
                ];
            })->values()->all();

            $body = json_encode([
                'source' => (string) config('domain_events.source', 'game-backend'),
                'run'    => $name,
                'from_id' => $lastId + 1,
                'to_id'  => (int) $users->last()->id,
                'members' => $members,
            ], JSON_UNESCAPED_UNICODE);

            try {
                $res = Http::withHeaders($signer->headers($body))->withBody($body, 'application/json')
                    ->connectTimeout(5)->timeout(60)->post($url);
                if (!$res->successful()) {
                    throw new \RuntimeException('HTTP ' . $res->status() . ' ' . Str::limit($res->body(), 300));
                }
            } catch (\Throwable $e) {
                DB::table('domain_export_runs')->where('name', $name)->update(['status' => 'failed', 'last_error' => Str::limit($e->getMessage(), 1000), 'updated_at' => now()]);
                $this->error('หยุดที่ last_id=' . $lastId . ' : ' . $e->getMessage() . ' (รันใหม่เพื่อทำต่อ)');
                return self::FAILURE;
            }

            $lastId = (int) $users->last()->id;
            DB::table('domain_export_runs')->where('name', $name)->update([
                'status' => 'running', 'last_id' => $lastId, 'last_error' => null,
                'sent' => DB::raw('sent + ' . count($members)),
                'duplicates' => DB::raw('duplicates + ' . (int) $res->json('unchanged', 0)),
                'updated_at' => now(),
            ]);
            $this->line("ส่งแล้วถึง id {$lastId} (" . count($members) . ' คน, ' . json_encode($res->json()) . ')');

            if ($maxBatches && ++$batches >= $maxBatches) {
                $this->info('หยุดตาม --max-batches (รันใหม่เพื่อทำต่อ)');
                return self::SUCCESS;
            }
        }
    }
}
