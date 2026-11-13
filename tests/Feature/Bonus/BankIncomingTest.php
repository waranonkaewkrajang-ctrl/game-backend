<?php

namespace Tests\Feature\Bonus;

use App\Http\Controllers\Admin\AdminUnmatchedDepositController;
use App\Models\BankIncomingTx;
use App\Models\Deposit;
use App\Models\Setting;
use App\Models\UnmatchedDeposit;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BankIncomingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ด่านตรวจเงินเข้า (bot LINE ธนาคาร) — กันเบิ้ลตอน restart / LINE หลุด + กฎจับคู่ใหม่
 * sqlite in-memory เท่านั้น ไม่มีเงินจริง ข้อมูลจำลองจากเหตุการณ์ 13/11/2026 (ยอด/เวลา/เลขบัญชี 4 ตัวท้าย)
 */
class BankIncomingTest extends TestCase
{
    use CreatesMoneySchema;

    private const TZ = 'Asia/Bangkok';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMoneySchema();
        Http::fake();
        config(['logging.default' => 'null', 'app.timezone' => self::TZ]);
        date_default_timezone_set(self::TZ);
        $this->at('2026-11-13 14:02:20');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    //  ปกติ
    // ------------------------------------------------------------------

    public function test_live_message_matching_account_and_time_is_auto_approved_once(): void
    {
        $u = $this->user('pp15', '123-4-57206');            // มีขีดในเลขบัญชี ก็ต้องเทียบได้
        $d = $this->pending($u, 100, '2026-11-13 13:40:49');

        $r = $this->ingest(100, '13/11/2026 13:42', 'MISSRATTIKAN X-7206 กรุงไทย');

        $this->assertSame('auto_approved', $r['result']);
        $this->assertSame($d->id, $r['deposit_id']);
        $this->assertSame('approved', $d->fresh()->status);
        $this->assertSame('auto', $d->fresh()->approved_method);
        $this->assertBalance($u, 100);

        // ข้อความเดิมถูกส่งมาอีก 3 รอบ (restart / LINE โหลดแชทเก่า / cache ล้าง) → ไม่เติมซ้ำ
        foreach (['live', 'backlog', 'live'] as $mode) {
            $again = $this->ingest(100, '13/11/2026 13:42', 'MISSRATTIKAN X-7206 กรุงไทย', $mode);
            $this->assertSame('duplicate', $again['result']);
        }
        $this->assertBalance($u, 100);
        $this->assertSame(4, BankIncomingTx::first()->seen_count);
        $this->assertSame(1, BankIncomingTx::count());
        $this->assertSame(0, UnmatchedDeposit::count());
    }

    public function test_same_message_read_by_json_and_text_paths_counts_once(): void
    {
        $u = $this->user('a1', '0001117206');
        $this->pending($u, 100, '2026-11-13 14:00:00');

        $this->assertSame('auto_approved', $this->ingest(100, '13/11/2026 14:01', 'MISSRATTIKAN X-7206 กรุงไทย', 'live', 'SCB', 'scb_json')['result']);
        // ทางข้อความ: ธนาคาร/รูปแบบชื่อไม่เหมือนกัน แต่เป็นข้อความเดียวกัน
        $this->assertSame('duplicate', $this->ingest('100.00', '13/11/2026 14:01', 'MISSRATTIKAN  X 7206', 'live', 'KTB', 'text')['result']);
        $this->assertBalance($u, 100);
    }

    // ------------------------------------------------------------------
    //  เหตุการณ์จริง 13/11 — bot restart แล้วอ่านข้อความเก่าซ้ำ
    // ------------------------------------------------------------------

    public function test_incident_replay_after_restart_never_double_credits(): void
    {
        // ---- ช่วงเช้า: bot ปกติ ข้อความเข้ามาแบบ live และอนุมัติถูกคน
        $ponpimon = $this->user('Ponpimon', '0004564656');
        $pxxm     = $this->user('Pxxm', '0004564384');
        $u5555    = $this->user('5555', '0004567604');
        $cccc     = $this->user('Cccc1234', '0004567734');
        $pp15     = $this->user('PP15', '0004567206');

        $this->at('2026-11-13 01:46:09'); $this->pending($ponpimon, 100, '2026-11-13 01:45:35');
        $this->assertSame('auto_approved', $this->ingest(100, '13/11/2026 01:46', 'น.ส. พรพิมล ค X-4656 กสิกรไทย')['result']);
        $this->at('2026-11-13 05:46:32'); $this->pending($pxxm, 100, '2026-11-13 05:46:05');
        $this->assertSame('auto_approved', $this->ingest(100, '13/11/2026 05:46', 'นาย พิเชษฐ์ แ X-4384 กสิกรไทย')['result']);
        $this->at('2026-11-13 11:56:15');
        $this->pending($cccc, 600, '2026-11-13 11:55:32');
        $second600 = $this->pending($cccc, 600, '2026-11-13 11:55:37'); // ลูกค้ากดแจ้งฝากซ้ำ 2 ครั้ง โอนจริงครั้งเดียว
        $this->assertSame('auto_approved', $this->ingest(600, '13/11/2026 11:56', 'CHITAWAN RACH X-7734 ไทยพาณิชย์')['result']);
        $this->at('2026-11-13 13:09:25'); $this->pending($u5555, 100, '2026-11-13 13:08:40');
        $this->assertSame('auto_approved', $this->ingest(100, '13/11/2026 13:09', 'นางสาว จริยาภ X-7604 ออมสิน')['result']);

        // ---- 13:36 restart / LINE หลุด, PP15 กดแจ้งฝาก 100 ไว้ 4 ครั้ง, โอนจริง 13:42 ครั้งเดียว
        $pp = [];
        foreach (['13:40:49', '13:41:42', '13:42:31', '13:46:58'] as $t) {
            $pp[] = $this->pending($pp15, 100, "2026-11-13 {$t}");
        }

        // ---- 14:02 bot กลับมา อ่านแชทย้อนหลังทั้งหมด (ช่วงอ่านย้อนหลัง = backlog)
        $this->at('2026-11-13 14:02:20');
        $replay = [
            [100, '13/11/2026 13:42', 'MISSRATTIKAN X-7206 กรุงไทย'],
            [100, '13/11/2026 13:09', 'นางสาว จริยาภ X-7604 ออมสิน'],
            [600, '13/11/2026 11:56', 'CHITAWAN RACH X-7734 ไทยพาณิชย์'],
            [100, '13/11/2026 05:46', 'นาย พิเชษฐ์ แ X-4384 กสิกรไทย'],
            [100, '13/11/2026 01:46', 'น.ส. พรพิมล ค X-4656 กสิกรไทย'],
        ];
        $results = [];
        foreach ($replay as [$amt, $t, $from]) {
            $results[$t] = $this->ingest($amt, $t, $from, 'backlog')['result'];
        }

        // ข้อความเก่าที่เคยใช้แล้ว → ข้ามทั้งหมด
        $this->assertSame('duplicate', $results['13/11/2026 13:09']);
        $this->assertSame('duplicate', $results['13/11/2026 11:56']);
        $this->assertSame('duplicate', $results['13/11/2026 05:46']);
        $this->assertSame('duplicate', $results['13/11/2026 01:46']);
        // เงินจริงของ PP15 ที่เข้ามาช่วง bot ดับ → ไม่ auto ส่งให้แอดมินตรวจ (ไม่หาย ไม่เบิ้ล)
        $this->assertSame('review', $results['13/11/2026 13:42']);

        // ผล: ไม่มีใครได้เงินเกิน (เดิมวันนั้น PP15 เกิน 300, Cccc1234 เกิน 600)
        $this->assertBalance($pp15, 0);
        $this->assertBalance($cccc, 600);
        $this->assertBalance($ponpimon, 100);
        $this->assertBalance($pxxm, 100);
        $this->assertBalance($u5555, 100);
        foreach ($pp as $d) {
            $this->assertSame('pending', $d->fresh()->status);
        }
        $this->assertSame('pending', $second600->fresh()->status);

        // ยอดค้างเกิด 1 รายการ (เงินจริงของ PP15) ไม่ใช่ 36 รายการ และมีคำแนะนำให้แอดมิน
        $this->assertSame(1, UnmatchedDeposit::count());
        $um = UnmatchedDeposit::first();
        $this->assertStringContainsString('รอตรวจ', $um->note);
        $this->assertStringContainsString('#' . $pp[0]->id, $um->note);

        // bot ล่มอีกรอบ อ่านซ้ำอีก → ไม่มีอะไรเพิ่ม
        foreach ($replay as [$amt, $t, $from]) {
            $this->assertSame('duplicate', $this->ingest($amt, $t, $from, 'backlog')['result']);
        }
        $this->assertSame(1, UnmatchedDeposit::count());
    }

    public function test_incident_without_ledger_history_is_still_safe(): void
    {
        // กรณีสมุดบัญชียังว่าง (เพิ่งเริ่มใช้) แต่ bot อ่านข้อความเก่าขึ้นมาแบบ live — กฎจับคู่ต้องกันเอง
        $pp15 = $this->user('PP15', '0004567206');
        $cccc = $this->user('Cccc1234', '0004567734');
        foreach (['13:40:49', '13:41:42', '13:42:31', '13:46:58'] as $t) {
            $this->pending($pp15, 100, "2026-11-13 {$t}");
        }
        $this->pending($cccc, 600, '2026-11-13 11:55:37');

        $r1309 = $this->ingest(100, '13/11/2026 13:09', 'นางสาว จริยาภ X-7604 ออมสิน');   // PP15 แจ้งฝาก 13:40 = โอนก่อนแจ้ง 31 นาที (เกินกรอบ 15)
        $r0546 = $this->ingest(100, '13/11/2026 05:46', 'นาย พิเชษฐ์ แ X-4384 กสิกรไทย'); // เก่ากว่า 8 ชม.
        $r1156 = $this->ingest(600, '13/11/2026 11:56', 'CHITAWAN RACH X-7734 ไทยพาณิชย์'); // บัญชีตรง แต่เก่าเกิน 30 นาที
        $this->at('2026-11-13 13:45:00');
        $r1344 = $this->ingest(100, '13/11/2026 13:44', 'นางสาว จริยาภ X-7604 ออมสิน');   // อยู่ในกรอบเวลา แต่คนละบัญชีกับ PP15

        $this->assertSame('unmatched', $r1309['result']);
        $this->assertSame('no_pending_deposit', $r1309['reason']);
        $this->assertSame('unmatched', $r0546['result']);
        $this->assertSame('no_pending_deposit', $r0546['reason']);
        $this->assertSame('review', $r1344['result']);
        $this->assertSame('account_mismatch', $r1344['reason']);
        $this->assertSame('review', $r1156['result']);
        $this->assertSame('too_old', $r1156['reason']);

        $this->assertBalance($pp15, 0);
        $this->assertBalance($cccc, 0);
    }

    // ------------------------------------------------------------------
    //  กฎจับคู่
    // ------------------------------------------------------------------

    public function test_single_candidate_with_wrong_account_is_not_auto_approved(): void
    {
        // ช่องโหว่เดิม: มีรายการแจ้งฝากยอดนี้แค่รายการเดียว → bot เดิมอนุมัติเลยไม่ดูเลขบัญชี
        $u = $this->user('victim', '0001111111');
        $d = $this->pending($u, 500, '2026-11-13 14:00:00');

        $r = $this->ingest(500, '13/11/2026 14:01', 'นาย คนอื่น X-9999 กสิกรไทย');

        $this->assertSame('review', $r['result']);
        $this->assertSame('account_mismatch', $r['reason']);
        $this->assertSame('pending', $d->fresh()->status);
        $this->assertBalance($u, 0);
    }

    public function test_account_rule_can_be_relaxed_by_setting(): void
    {
        Setting::setValue('bank_require_account_match', 'false');
        $u = $this->user('relaxed', '0001111111');
        $this->pending($u, 500, '2026-11-13 14:00:00');

        $this->assertSame('auto_approved', $this->ingest(500, '13/11/2026 14:01', 'นาย คนอื่น X-9999 กสิกรไทย')['result']);
    }

    public function test_message_without_account_digits_goes_to_review(): void
    {
        $u = $this->user('noacc', '0001112222');
        $this->pending($u, 300, '2026-11-13 14:00:00');

        $r = $this->ingest(300, '13/11/2026 14:01', 'นาย ไม่มีเลขบัญชี');

        $this->assertSame('review', $r['result']);
        $this->assertSame('no_account_in_message', $r['reason']);
        $this->assertBalance($u, 0);
    }

    public function test_transfer_outside_time_window_is_not_matched(): void
    {
        $u = $this->user('late', '0001117206');
        $this->pending($u, 100, '2026-11-13 11:00:00');   // แจ้งฝาก 3 ชม. ก่อนโอน (เกิน 60 นาที)

        $r = $this->ingest(100, '13/11/2026 14:01', 'X X-7206 กรุงไทย');

        $this->assertSame('unmatched', $r['result']);
        $this->assertBalance($u, 0);
    }

    public function test_two_customers_with_same_last4_and_amount_go_to_review(): void
    {
        $a = $this->user('twinA', '1110007206');
        $b = $this->user('twinB', '2220007206');
        $this->pending($a, 200, '2026-11-13 14:00:00');
        $this->pending($b, 200, '2026-11-13 14:00:30');

        $r = $this->ingest(200, '13/11/2026 14:01', 'X X-7206 กรุงไทย');

        $this->assertSame('review', $r['result']);
        $this->assertSame('multiple_users', $r['reason']);
        $this->assertBalance($a, 0);
        $this->assertBalance($b, 0);
    }

    public function test_customer_with_several_requests_gets_one_credit_per_transfer(): void
    {
        $u = $this->user('multi', '0001117206');
        $d1 = $this->pending($u, 100, '2026-11-13 13:58:00');
        $d2 = $this->pending($u, 100, '2026-11-13 13:59:00');
        $d3 = $this->pending($u, 100, '2026-11-13 14:00:00');

        $this->assertSame($d1->id, $this->ingest(100, '13/11/2026 14:00', 'X X-7206')['deposit_id']);
        $this->assertSame($d2->id, $this->ingest(100, '13/11/2026 14:01', 'X X-7206')['deposit_id']);

        $this->assertBalance($u, 200);
        $this->assertSame('pending', $d3->fresh()->status);
    }

    public function test_truewallet_requests_are_never_matched_by_bank_messages(): void
    {
        $u = $this->user('tw', '0001117206');
        $this->pending($u, 100, '2026-11-13 14:00:00', 'truewallet');

        $this->assertSame('unmatched', $this->ingest(100, '13/11/2026 14:01', 'X X-7206')['result']);
        $this->assertBalance($u, 0);
    }

    // ------------------------------------------------------------------
    //  เบรกฉุกเฉิน / ตั้งค่า / วันเริ่มใช้
    // ------------------------------------------------------------------

    public function test_burst_pauses_auto_approval_until_resumed(): void
    {
        Setting::setValue('bank_burst_max', '2');
        $users = [];
        foreach (range(1, 4) as $i) {
            $users[$i] = $this->user("burst{$i}", "00011{$i}720{$i}");
            $this->pending($users[$i], 100, '2026-11-13 14:00:00');
        }

        $this->assertSame('auto_approved', $this->ingest(100, '13/11/2026 14:01', 'X X-7201')['result']);
        $this->assertSame('auto_approved', $this->ingest(100, '13/11/2026 14:01', 'X X-7202')['result']);
        $third = $this->ingest(100, '13/11/2026 14:01', 'X X-7203');
        $this->assertSame('review', $third['result']);
        $this->assertSame('burst', $third['reason']);
        $this->assertNotSame('', Setting::getValue('bank_auto_paused_until'));

        $this->at('2026-11-13 14:05:00'); // พ้นช่วงนับแล้ว แต่ยังอยู่ในช่วงหยุด
        $fourth = $this->ingest(100, '13/11/2026 14:04', 'X X-7204');
        $this->assertSame('paused', $fourth['reason']);

        Setting::setValue('bank_auto_paused_until', '');     // = ปุ่ม resume
        $users[5] = $this->user('burst5', '0001157205');
        $this->pending($users[5], 100, '2026-11-13 14:04:00');
        $this->assertSame('auto_approved', $this->ingest(100, '13/11/2026 14:05', 'X X-7205')['result']);
        $this->assertBalance($users[3], 0);
        $this->assertBalance($users[4], 0);
    }

    public function test_auto_disabled_setting_sends_everything_to_review(): void
    {
        Setting::setValue('bank_auto_approve_enabled', 'false');
        $u = $this->user('off', '0001117206');
        $this->pending($u, 100, '2026-11-13 14:00:00');

        $r = $this->ingest(100, '13/11/2026 14:01', 'X X-7206');
        $this->assertSame('review', $r['result']);
        $this->assertSame('auto_disabled', $r['reason']);
        $this->assertBalance($u, 0);
    }

    public function test_messages_before_cutover_are_recorded_but_ignored(): void
    {
        Setting::setValue('bank_ingest_since', '2026-11-13 14:00:00');

        $r = $this->ingest(100, '13/11/2026 11:27', 'น.ส. จุฑาทิพย X-8770 ทีทีบี', 'backlog');

        $this->assertSame('ignored', $r['result']);
        $this->assertSame(0, UnmatchedDeposit::count());
        $this->assertSame('before_cutover', BankIncomingTx::first()->status);
    }

    // ------------------------------------------------------------------
    //  ยอดค้าง (endpoint เดิม) + การผูกตอนแอดมินอนุมัติ
    // ------------------------------------------------------------------

    public function test_legacy_unmatched_endpoint_does_not_create_duplicates(): void
    {
        $payload = ['bank' => 'SCB', 'amount' => 250, 'from_account' => 'น.ส. จุฑาทิพย X-8770 ทีทีบี', 'tx_time' => '12/11/2026 11:27'];
        $c = app(AdminUnmatchedDepositController::class);

        $this->assertSame(201, $c->store(Request::create('/x', 'POST', $payload))->getStatusCode());
        $this->assertSame(200, $c->store(Request::create('/x', 'POST', $payload))->getStatusCode());
        $this->assertSame(200, $c->store(Request::create('/x', 'POST', $payload))->getStatusCode());
        $this->assertSame(1, UnmatchedDeposit::count());
    }

    public function test_admin_approving_a_review_item_links_the_ledger(): void
    {
        $u = $this->user('reviewed', '0001117206');
        $r = $this->ingest(100, '13/11/2026 14:01', 'X X-9999');   // ไม่มีรายการแจ้งฝาก → ยอดค้าง
        $this->assertSame('unmatched', $r['result']);

        $req = Request::create('/x', 'POST', ['username_or_phone' => 'reviewed']);
        $req->setUserResolver(fn () => (object) ['id' => 7]);
        $res = app(AdminUnmatchedDepositController::class)->approve($req, UnmatchedDeposit::find($r['unmatched_id']));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertBalance($u, 100);
        $tx = BankIncomingTx::first();
        $this->assertSame('manual_approved', $tx->status);
        $this->assertNotNull($tx->deposit_id);
    }

    // ------------------------------------------------------------------
    //  แปลงเวลา
    // ------------------------------------------------------------------

    public static function times(): array
    {
        return [
            'ค.ศ. 4 หลัก' => ['13/11/2026 13:42', '2026-11-13 13:42'],
            'พ.ศ. 4 หลัก' => ['13/11/2569 13:42', '2026-11-13 13:42'],
            'พ.ศ. 2 หลัก' => ['13/11/69 13:42', '2026-11-13 13:42'],
            'ค.ศ. 2 หลัก' => ['13/11/26 13:42', '2026-11-13 13:42'],
            'เลขหลักเดียว' => ['3/1/2026 9:05', '2026-01-03 09:05'],
            'วันที่ผิด'     => ['31/02/2026 10:00', null],
            'ไม่มีเวลา'     => ['13/11/2026', null],
            'ว่าง'          => ['', null],
        ];
    }

    #[DataProvider('times')]
    public function test_parse_tx_time(string $raw, ?string $expected): void
    {
        $got = BankIncomingService::parseTxTime($raw);
        $this->assertSame($expected, $got?->format('Y-m-d H:i'));
    }

    // ------------------------------------------------------------------
    //  helpers
    // ------------------------------------------------------------------

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, self::TZ));
    }

    private function ingest($amount, string $time, string $from, string $mode = 'live', string $bank = 'SCB', string $source = 'scb_json'): array
    {
        return app(BankIncomingService::class)->ingest(
            ['bank' => $bank, 'amount' => $amount, 'tx_time' => $time, 'from' => $from, 'source' => $source, 'mode' => $mode],
            1
        );
    }

    private function user(string $username, string $account): User
    {
        $u = User::create([
            'username' => $username, 'phone' => '08' . str_pad((string) (User::count() + 1), 8, '0', STR_PAD_LEFT),
            'password' => 'x', 'bank_code' => 'SCB', 'bank_account' => $account, 'bank_name' => $username,
        ]);
        Wallet::create(['user_id' => $u->id, 'balance' => 0]);
        return $u;
    }

    private function pending(User $u, float $amount, string $createdAt, string $channel = 'bank_transfer'): Deposit
    {
        $d = Deposit::create([
            'user_id' => $u->id, 'reference_id' => 'DEP-' . uniqid('', true), 'amount' => $amount,
            'channel' => $channel, 'status' => 'pending',
        ]);
        $d->forceFill(['created_at' => Carbon::parse($createdAt, self::TZ)])->saveQuietly();
        return $d->fresh();
    }

    private function assertBalance(User $u, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, (float) Wallet::where('user_id', $u->id)->value('balance'), 0.001, "ยอดเงินของ {$u->username}");
    }
}
