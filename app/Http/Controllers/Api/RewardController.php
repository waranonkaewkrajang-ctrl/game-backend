<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use App\Models\Setting;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RewardController extends Controller
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    /**
     * ดูยอดรอรับ (เฉพาะที่ยังไม่หมดอายุ)
     */
    public function summary(Request $request)
    {
        $user = $request->user();
        $expireDays = Reward::expireDaysFor('cashback');
        $referralExpireDays = Reward::expireDaysFor('referral');

        // ยอด pending ที่ยังไม่หมดอายุ
        $cashbackPending = $this->getPendingAmount($user->id, 'cashback', $expireDays);
        $referralPending = $this->getPendingAmount($user->id, 'referral', $referralExpireDays);

        // ยอดที่รับแล้ว
        $cashbackClaimed = (float) Reward::where('user_id', $user->id)
            ->where('type', 'cashback')->where('status', 'claimed')->sum('amount');
        $referralClaimed = (float) Reward::where('user_id', $user->id)
            ->where('type', 'referral')->where('status', 'claimed')->sum('amount');

        // ยอดที่หมดอายุ
        $cashbackExpired = (float) Reward::where('user_id', $user->id)
            ->where('type', 'cashback')->where('status', 'expired')->sum('amount');
        $referralExpired = (float) Reward::where('user_id', $user->id)
            ->where('type', 'referral')->where('status', 'expired')->sum('amount');

        return response()->json([
            'status' => 'success',
            'data' => [
                'cashback' => ['pending' => $cashbackPending, 'claimed' => $cashbackClaimed, 'expired' => $cashbackExpired],
                'referral' => ['pending' => $referralPending, 'claimed' => $referralClaimed, 'expired' => $referralExpired],
                'expire_days' => $expireDays,
                'referral_expire_days' => $referralExpireDays,
            ],
        ]);
    }

    public function claimCashback(Request $request)
    {
        return $this->claimReward($request->user(), 'cashback');
    }

    public function claimReferral(Request $request)
    {
        return $this->claimReward($request->user(), 'referral');
    }

    public function history(Request $request)
    {
        $query = Reward::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc');
        if ($request->filled('type')) $query->where('type', $request->type);
        if ($request->filled('status')) $query->where('status', $request->status);

        $page = $query->paginate(20);
        // ค่าแนะนำ: username ของเพื่อนคือเบอร์โทร → ส่งให้ผู้แนะนำแบบปิดบางส่วนเท่านั้น
        $page->getCollection()->transform(function (Reward $r) {
            $meta = $r->meta;
            if (is_array($meta) && isset($meta['from_username'])) {
                $meta['from_username'] = self::maskName((string) $meta['from_username']);
                $r->meta = $meta;
            }
            return $r;
        });

        return response()->json(['status' => 'success', 'data' => $page]);
    }

    /**
     * เพื่อนที่ลูกค้าคนนี้แนะนำ + เงื่อนไขค่าแนะนำปัจจุบัน (ไว้แสดงหน้า /rewards/referral)
     * ไม่ส่งข้อมูลส่วนตัวของเพื่อน: ชื่อปิดบางส่วน, ไม่มีเบอร์เต็ม/บัญชี/ยอดเล่น
     */
    public function referralFriends(Request $request)
    {
        $user = $request->user();

        $friends = User::where('referred_by', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(200)
            ->get(['id', 'username', 'status', 'created_at']);

        // ค่าแนะนำที่ได้จากเพื่อนแต่ละคน (รับแล้ว + รอรับ)
        $earned = [];
        Reward::where('user_id', $user->id)->where('type', 'referral')
            ->whereIn('status', ['claimed', 'pending'])
            ->get(['amount', 'meta'])
            ->each(function (Reward $r) use (&$earned) {
                $from = $r->meta['from_user_id'] ?? null;
                if ($from !== null) $earned[$from] = ($earned[$from] ?? 0) + (float) $r->amount;
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'count'   => User::where('referred_by', $user->id)->count(),
                'friends' => $friends->map(fn (User $f) => [
                    'name'      => self::maskName((string) $f->username),
                    'joined_at' => $f->created_at,
                    'active'    => $f->status === 'active',
                    'earned'    => round($earned[$f->id] ?? 0, 2),
                ])->values(),
                'terms' => [
                    'percent'     => (float) Setting::getValue('referral_percent', 0),
                    'expire_days' => Reward::expireDaysFor('referral'),
                    'min_payout'  => 1, // CalculateCashback: ต่ำกว่า 1 บาทไม่สร้างรางวัล
                ],
            ],
        ]);
    }

    /** 0812345678 → 081-xxx-5678 · ชื่อที่ไม่ใช่เบอร์ → 2 ตัวแรก + *** */
    private static function maskName(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^\d{9,10}$/', $name)) {
            return substr($name, 0, 3) . '-xxx-' . substr($name, -4);
        }
        return mb_substr($name, 0, 2) . '***';
    }

    /**
     * กดรับรางวัล (เฉพาะที่ยังไม่หมดอายุ)
     */
    private function claimReward($user, string $type)
    {
        $typeName = $type === 'cashback' ? 'ยอดเสีย' : 'ค่าแนะนำเพื่อน';
        $expireDays = Reward::expireDaysFor($type);

        return DB::transaction(function () use ($user, $type, $typeName, $expireDays) {
            $query = Reward::where('user_id', $user->id)
                ->where('type', $type)
                ->where('status', 'pending')
                ->lockForUpdate();

            // ถ้าตั้งวันหมดอายุ → รับได้เฉพาะที่ยังไม่หมดอายุ (หมดเที่ยงคืน)
            if ($expireDays > 0) {
                $query->where('created_at', '>=', Carbon::now()->subDays($expireDays));
            }

            $pendingRewards = $query->get();

            if ($pendingRewards->isEmpty()) {
                return response()->json(['status' => 'error', 'message' => "ไม่มี{$typeName}ที่รอรับ"], 400);
            }

            $totalAmount = $pendingRewards->sum('amount');

            // อัปเดตเฉพาะรายการที่ยังไม่หมดอายุ
            Reward::whereIn('id', $pendingRewards->pluck('id'))
                ->update(['status' => 'claimed', 'claimed_at' => now()]);

            $this->walletService->addBonus(
                $user, $totalAmount,
                "รับ{$typeName} ฿" . number_format($totalAmount, 2),
                ['type' => "claim_{$type}", 'reward_count' => $pendingRewards->count()]
            );

            return response()->json([
                'status' => 'success',
                'message' => "รับ{$typeName}สำเร็จ ฿" . number_format($totalAmount, 2),
                'data' => [
                    'amount' => $totalAmount,
                    'count' => $pendingRewards->count(),
                    'balance' => $this->walletService->getBalance($user),
                ],
            ]);
        });
    }

    /**
     * ยอด pending ที่ยังไม่หมดอายุ
     */
    private function getPendingAmount(int $userId, string $type, int $expireDays): float
    {
        $query = Reward::where('user_id', $userId)
            ->where('type', $type)
            ->where('status', 'pending');

        if ($expireDays > 0) {
            $query->where('created_at', '>=', Carbon::now()->subDays($expireDays));
        }

        return (float) $query->sum('amount');
    }
}