<?php

namespace Tests\Feature\Bonus;

use App\Http\Controllers\Admin\AdminUnmatchedDepositController;
use App\Models\Deposit;
use App\Models\Promotion;
use App\Models\PromotionClaim;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\UnmatchedDeposit;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\DepositService;
use App\Services\PromotionService;
use App\Services\WithdrawalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * งาน 4.2 (ให้โบนัสตอนอนุมัติฝาก + log) และ 4.3 (กันอนุมัติ/ปฏิเสธซ้ำ)
 *
 * sqlite in-memory เท่านั้น — ไม่แตะฐานข้อมูลจริง ไม่มีรายการเงินจริง
 * เคส "ซ้ำ" จำลองด้วย model ที่โหลดไว้ก่อน (สถานะ pending ค้างในหน่วยความจำ)
 * ซึ่งเป็นรูปแบบเดียวกับแอดมิน 2 คนกดพร้อมกัน
 */
class DepositApprovalTest extends TestCase
{
    use CreatesMoneySchema;

    private User $user;
    private TestHandler $bonusLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMoneySchema();
        Http::fake();
        config(['logging.default' => 'null']);
        config(['logging.channels.promotion_bonus' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        $this->bonusLog = Log::channel('promotion_bonus')->getLogger()->getHandlers()[0];

        $this->user = User::create([
            'username' => 'dep_user', 'phone' => '0800000002', 'password' => 'x',
            'bank_code' => 'SCB', 'bank_account' => '1234567890', 'bank_name' => 'Test User',
        ]);
        Wallet::create(['user_id' => $this->user->id, 'balance' => 0]);
        Setting::create(['key' => 'default_turnover_multiplier', 'value' => '3']);
    }

    // ------------------------------------------------------------------
    //  4.3 ฝาก
    // ------------------------------------------------------------------

    public function test_approving_same_deposit_twice_credits_once(): void
    {
        $deposit = $this->deposit(500);
        $stale   = Deposit::find($deposit->id);

        app(DepositService::class)->approve($deposit, 1);

        $this->expectExceptionMessage('รายการนี้ถูกดำเนินการแล้ว');
        try {
            app(DepositService::class)->approve($stale, 2);
        } finally {
            $this->assertSame('500.00', $this->wallet()->balance);
            $this->assertSame(1, Transaction::count());
            $this->assertSame(1, Deposit::find($deposit->id)->approved_by);
        }
    }

    public function test_cannot_reject_deposit_after_approval(): void
    {
        $deposit = $this->deposit(500);
        $stale   = Deposit::find($deposit->id);

        app(DepositService::class)->approve($deposit, 1);

        $this->expectExceptionMessage('รายการนี้ถูกดำเนินการแล้ว');
        try {
            app(DepositService::class)->reject($stale, 2, 'สลิปปลอม');
        } finally {
            $this->assertSame('approved', Deposit::find($deposit->id)->status);
            $this->assertSame('500.00', $this->wallet()->balance);
        }
    }

    public function test_approve_and_reject_still_work_normally(): void
    {
        $approved = app(DepositService::class)->approve($this->deposit(300), 1);
        $rejected = app(DepositService::class)->reject($this->deposit(200), 1, 'ไม่พบยอด');

        $this->assertSame('approved', $approved->status);
        $this->assertSame(1, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('ไม่พบยอด', $rejected->reject_reason);
        $this->assertSame('300.00', $this->wallet()->balance);
    }

    // ------------------------------------------------------------------
    //  4.3 ถอน
    // ------------------------------------------------------------------

    public function test_rejecting_same_withdrawal_twice_refunds_once(): void
    {
        $wd    = $this->withdrawal(400);
        $stale = Withdrawal::find($wd->id);

        app(WithdrawalService::class)->reject($wd, 1, 'บัญชีผิด');

        $this->expectExceptionMessage('รายการนี้ถูกดำเนินการแล้ว');
        try {
            app(WithdrawalService::class)->reject($stale, 2, 'บัญชีผิด');
        } finally {
            $this->assertSame('400.00', $this->wallet()->balance);
            $this->assertSame(1, Transaction::count());
        }
    }

    public function test_cannot_refund_withdrawal_after_approval(): void
    {
        $wd    = $this->withdrawal(400);
        $stale = Withdrawal::find($wd->id);

        app(WithdrawalService::class)->approve($wd, 1);

        $this->expectExceptionMessage('รายการนี้ถูกดำเนินการแล้ว');
        try {
            app(WithdrawalService::class)->reject($stale, 2, 'บัญชีผิด');
        } finally {
            $this->assertSame('approved', Withdrawal::find($wd->id)->status);
            $this->assertSame('0.00', $this->wallet()->balance);
            $this->assertSame(0, Transaction::count());
        }
    }

    // ------------------------------------------------------------------
    //  4.3 ยอดค้าง
    // ------------------------------------------------------------------

    public function test_approving_unmatched_deposit_twice_credits_once(): void
    {
        $item  = UnmatchedDeposit::create(['bank' => 'KBANK', 'amount' => 750, 'from_account' => 'xxx-1234'])->fresh();   // เหมือน route model binding
        $stale = UnmatchedDeposit::find($item->id);

        $first = $this->approveUnmatched($item);
        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame('success', $first->getData(true)['status']);

        $second = $this->approveUnmatched($stale);
        $this->assertSame(400, $second->getStatusCode());
        $this->assertSame(['status' => 'error', 'message' => 'รายการนี้ดำเนินการแล้ว'], $second->getData(true));

        $this->assertSame('750.00', $this->wallet()->balance);
        $this->assertSame(1, Deposit::count());
        $this->assertSame(1, Transaction::count());
        $this->assertSame($this->user->id, UnmatchedDeposit::find($item->id)->matched_user_id);
    }

    // ------------------------------------------------------------------
    //  4.2 โบนัสตอนอนุมัติฝาก
    // ------------------------------------------------------------------

    public function test_approval_with_promotion_grants_bonus_and_logs_granted(): void
    {
        $promo   = $this->promotion(['bonus_percent' => 10, 'turnover_multiplier' => 5]);
        $deposit = $this->deposit(1000, $promo);

        app(DepositService::class)->approve($deposit, 7);

        $claim = PromotionClaim::sole();
        $this->assertSame($deposit->id, $claim->deposit_id);
        $this->assertSame('100.00', $claim->bonus_amount);
        $this->assertSame('5500.00', $claim->turnover_required);
        $this->assertSame('1100.00', $this->wallet()->balance);

        $rec = $this->onlyLogRecord();
        $this->assertSame('GRANTED', $rec->message);
        $this->assertSame('INFO', $rec->level->getName());
        $this->assertSame($deposit->id, $rec->context['deposit_id']);
        $this->assertSame($promo->id, $rec->context['promotion_id']);
        $this->assertSame(7, $rec->context['approved_by']);
        $this->assertSame($claim->id, $rec->context['claim_id']);
        $this->assertSame('100.00', $rec->context['bonus']);
    }

    public function test_auto_approval_without_admin_also_grants_bonus(): void
    {
        // TrueWalletWebhookController เรียก approve($deposit, null)
        $promo = $this->promotion(['bonus_percent' => 10, 'turnover_multiplier' => 1]);

        app(DepositService::class)->approve($this->deposit(500, $promo), null);

        $this->assertSame(1, PromotionClaim::count());
        $this->assertSame('GRANTED', $this->onlyLogRecord()->message);
        $this->assertNull($this->onlyLogRecord()->context['approved_by']);
    }

    #[DataProvider('skipCases')]
    public function test_each_skip_reason_is_logged(string $expectedCode, \Closure $arrange): void
    {
        $deposit = $arrange($this);

        app(DepositService::class)->approve($deposit, 1);

        $rec = $this->onlyLogRecord();
        $this->assertSame('SKIPPED', $rec->message);
        $this->assertSame('WARNING', $rec->level->getName());
        $this->assertSame($expectedCode, $rec->context['code']);
        $this->assertNotEmpty($rec->context['reason']);
        $this->assertSame('approved', Deposit::find($deposit->id)->status, 'ยอดฝากต้องอนุมัติตามปกติ');
    }

    public static function skipCases(): array
    {
        return [
            'โปรปิด'           => ['inactive', fn (self $t) => $t->deposit(1000, $t->promotion(['is_active' => false]))],
            'โปรยังไม่เริ่ม'     => ['not_started', fn (self $t) => $t->deposit(1000, $t->promotion(['start_at' => now()->addDay()]))],
            'โปรหมดอายุ'       => ['ended', fn (self $t) => $t->deposit(1000, $t->promotion(['end_at' => now()->subDay()]))],
            'รับครบต่อคน'       => ['user_limit', function (self $t) {
                $p = $t->promotion([]);
                $t->claim($p, 'completed');
                return $t->deposit(1000, $p);
            }],
            'รับครบทั้งระบบ'     => ['global_limit', function (self $t) {
                $p = $t->promotion(['max_claims' => 1, 'claims_per_user' => 5]);
                $other = User::create(['username' => 'other', 'phone' => '0800000099', 'password' => 'x']);
                PromotionClaim::create(['user_id' => $other->id, 'promotion_id' => $p->id, 'bonus_amount' => 10, 'status' => 'completed', 'turnover_completed' => true]);
                return $t->deposit(1000, $p);
            }],
            'ติดเทิร์นโปรอื่น'    => ['active_turnover', function (self $t) {
                $t->claim($t->promotion(['title' => 'โปรเก่า']), 'active');
                return $t->deposit(1000, $t->promotion([]));
            }],
            'ยอดต่ำกว่าขั้นต่ำ'   => ['below_min_deposit', fn (self $t) => $t->deposit(50, $t->promotion(['min_deposit' => 100]))],
            'โบนัสเป็น 0'       => ['zero_bonus', fn (self $t) => $t->deposit(1000, $t->promotion(['bonus_percent' => 0]))],
            'โปรถูกลบ'         => ['promotion_missing', function (self $t) {
                $p = $t->promotion([]);
                $d = $t->deposit(1000, $p);
                $p->delete();
                return $d;
            }],
            'ได้โบนัสไปแล้ว'     => ['already_granted', function (self $t) {
                $p = $t->promotion(['claims_per_user' => 5]);
                $d = $t->deposit(1000, $p);
                PromotionClaim::create(['user_id' => $t->user->id, 'promotion_id' => $p->id, 'deposit_id' => $d->id, 'bonus_amount' => 100, 'status' => 'completed', 'turnover_completed' => true]);
                return $d;
            }],
        ];
    }

    public function test_bonus_failure_still_credits_deposit_and_logs_error(): void
    {
        $promo   = $this->promotion(['bonus_percent' => 10, 'turnover_multiplier' => 5]);
        $deposit = $this->deposit(1000, $promo);
        PromotionClaim::creating(fn () => throw new \RuntimeException('จำลอง claim พัง'));

        $result = app(DepositService::class)->approve($deposit, 1);

        $this->assertSame('approved', $result->status);
        $this->assertSame('1000.00', $this->wallet()->balance, 'ยอดฝากต้องเข้า โบนัสต้อง rollback');
        $this->assertSame('0.00', $this->wallet()->bonus_balance);
        $this->assertSame(0, PromotionClaim::count());
        $this->assertSame(1, Transaction::count());

        $rec = $this->onlyLogRecord();
        $this->assertSame('ERROR', $rec->message);
        $this->assertSame('exception', $rec->context['code']);
        $this->assertSame('จำลอง claim พัง', $rec->context['reason']);
    }

    public function test_deposit_without_promotion_writes_no_bonus_log(): void
    {
        app(DepositService::class)->approve($this->deposit(500), 1);

        $this->assertSame([], $this->bonusLog->getRecords());
        $this->assertSame(0, PromotionClaim::count());
    }

    public function test_apply_bonus_return_value_unchanged(): void
    {
        $service = app(PromotionService::class);

        $this->assertNull($service->applyBonus($this->user, $this->deposit(50, $this->promotion(['min_deposit' => 100]))));
        $this->assertInstanceOf(PromotionClaim::class, $service->applyBonus($this->user, $this->deposit(500, $this->promotion(['bonus_percent' => 10]))));
    }

    public function test_promotion_claim_endpoint_messages_unchanged(): void
    {
        $promo = $this->promotion([]);
        $check = app(PromotionService::class)->canClaim($this->user, $promo);
        $this->assertTrue($check['can_claim']);
        $this->assertNull($check['reason']);

        $this->claim($promo, 'completed');
        $check = app(PromotionService::class)->canClaim($this->user, $promo);
        $this->assertFalse($check['can_claim']);
        $this->assertSame('คุณรับโปรโมชันนี้ครบแล้ว', $check['reason']);
    }

    // ------------------------------------------------------------------

    private function onlyLogRecord(): \Monolog\LogRecord
    {
        $records = $this->bonusLog->getRecords();
        $this->assertCount(1, $records, 'ต้องมี log โบนัส 1 บรรทัดต่อการอนุมัติ');

        return $records[0];
    }

    private function wallet(): Wallet
    {
        return Wallet::where('user_id', $this->user->id)->first();
    }

    public function promotion(array $attrs): Promotion
    {
        return Promotion::create($attrs + [
            'title' => 'โปรทดสอบ', 'type' => 'deposit_bonus', 'min_deposit' => 100, 'bonus_percent' => 10,
            'turnover_multiplier' => 1, 'max_bonus' => 0, 'is_active' => true, 'claims_per_user' => 1,
        ]);
    }

    public function deposit(float $amount, ?Promotion $promo = null): Deposit
    {
        return Deposit::create([
            'user_id' => $this->user->id, 'reference_id' => 'DEP-' . uniqid('', true), 'amount' => $amount,
            'channel' => 'bank_transfer', 'status' => 'pending', 'promotion_id' => $promo?->id,
        ]);
    }

    public function claim(Promotion $promo, string $status): PromotionClaim
    {
        return PromotionClaim::create([
            'user_id' => $this->user->id, 'promotion_id' => $promo->id, 'bonus_amount' => 10,
            'status' => $status, 'turnover_completed' => $status === 'completed',
        ]);
    }

    private function withdrawal(float $amount): Withdrawal
    {
        // เงินถูกหักไปตอนสร้างคำขอแล้ว (createRequest) — กระเป๋าจึงเป็น 0
        return Withdrawal::create([
            'user_id' => $this->user->id, 'reference_id' => 'WD-' . uniqid('', true), 'amount' => $amount,
            'to_bank' => 'SCB', 'to_account' => '1234567890', 'to_name' => 'Test User', 'status' => 'pending',
            'balance_before' => $amount, 'balance_after' => 0,
        ]);
    }

    private function approveUnmatched(UnmatchedDeposit $item): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/admin/unmatched-deposits/' . $item->id . '/approve', 'POST', ['username_or_phone' => 'dep_user']);
        $request->setUserResolver(fn () => (object) ['id' => 1]);

        return app(AdminUnmatchedDepositController::class)->approve($request, $item);
    }
}
