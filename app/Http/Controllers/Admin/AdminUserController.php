<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    // =========================================================
    // ส่วนการจัดการลูกค้า (Users)
    // =========================================================

    public function index(Request $request): JsonResponse
    {
        $field = $request->input('search_by', 'all');   // all | username | phone | full_name | bank_account

        $users = User::with('wallet')
            ->when($request->search, function ($q, $search) use ($field) {
                $like = "%{$search}%";
                $digits = preg_replace('/\D/', '', $search);

                switch ($field) {
                    case 'username':
                        $q->where('username', 'like', $like);
                        break;
                    case 'phone':
                        $q->where('phone', 'like', $digits !== '' ? "%{$digits}%" : $like);
                        break;
                    case 'full_name':
                        // ค้นทีละคำ — เจอแม้เว้นวรรคไม่ตรง หรือพิมพ์แค่ชื่อหรือนามสกุล
                        $words = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY);
                        $q->where(function ($s) use ($words) {
                            foreach ($words as $w) {
                                $s->where('full_name', 'like', "%{$w}%");
                            }
                        });
                        break;
                    case 'bank_account':
                        $q->where('bank_account', 'like', $digits !== '' ? "%{$digits}%" : $like);
                        break;
                    default:   // ค้นทุกคอลัมน์
                        $q->where(function ($s) use ($like, $digits) {
                            $s->where('username', 'like', $like)
                              ->orWhere('phone', 'like', $like)
                              ->orWhere('full_name', 'like', $like)
                              ->orWhere('bank_account', 'like', $like);
                            if ($digits !== '') {
                                $s->orWhere('phone', 'like', "%{$digits}%")
                                  ->orWhere('bank_account', 'like', "%{$digits}%");
                            }
                        });
                }
            })
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'status' => 'success',
            'data'   => $users,
        ]);
    }

    // =========================================================
    // 🆕 สมัครสมาชิกให้ลูกค้า (Admin ตั้งข้อมูลให้)
    // =========================================================
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username'      => 'required|string|min:4|max:50|unique:users',
            'phone'         => 'required|string|min:10|max:20|unique:users',
            'password'      => 'required|string|min:6|max:50',
            'full_name'     => 'nullable|string|max:100',
            'bank_code'     => 'required|string|max:10',
            'bank_account'  => 'required|string|max:20|unique:users,bank_account',
            'bank_name'     => 'required|string|max:100',
            'referral_code' => 'nullable|string|max:20',
        ], [
            'username.unique'       => 'ชื่อผู้ใช้นี้ถูกใช้แล้ว',
            'username.required'     => 'กรุณากรอกชื่อผู้ใช้',
            'username.min'          => 'ชื่อผู้ใช้ต้องมีอย่างน้อย 4 ตัว',
            'phone.unique'          => 'เบอร์โทรนี้ถูกใช้แล้ว',
            'phone.required'        => 'กรุณากรอกเบอร์โทร',
            'phone.min'             => 'เบอร์โทรไม่ถูกต้อง',
            'password.required'     => 'กรุณาตั้งรหัสผ่าน',
            'password.min'          => 'รหัสผ่านอย่างน้อย 6 ตัว',
            'bank_code.required'    => 'กรุณาเลือกธนาคาร',
            'bank_account.unique'   => 'เลขบัญชีนี้ถูกใช้แล้ว',
            'bank_account.required' => 'กรุณากรอกเลขบัญชี',
            'bank_name.required'    => 'กรุณากรอกชื่อบัญชี',
        ]);

        $referredBy = null;
        if (!empty($data['referral_code'])) {
            $referrer = User::where('referral_code', $data['referral_code'])->first();
            $referredBy = $referrer?->id;
        }

        $user = User::create([
            'username'      => $data['username'],
            'phone'         => $data['phone'],
            'password'      => Hash::make($data['password']),
            'full_name'     => $data['full_name'] ?? null,
            'bank_code'     => $data['bank_code'],
            'bank_account'  => $data['bank_account'],
            'bank_name'     => $data['bank_name'],
            'referral_code' => strtoupper(\Illuminate\Support\Str::random(8)),
            'referred_by'   => $referredBy,
            'status'        => 'active',
        ]);

        $this->walletService->createWallet($user);

        // Audit log: แอดมินคนไหนสมัครให้
        $adminId = $request->user()?->id ?? 'unknown';
        \Log::info("Admin #{$adminId} created user #{$user->id} ({$user->username})");

        return response()->json([
            'status'  => 'success',
            'message' => 'สมัครสมาชิกให้ลูกค้าสำเร็จ',
            'data'    => $user->load('wallet'),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => $user->load(['wallet', 'deposits.approvedBy', 'withdrawals.approver']),
        ]);
    }

    /**
     * ดูเทิร์นโอเวอร์ของลูกค้า + แยกตามเกมที่เล่น
     */
    public function turnover(User $user): JsonResponse
    {
        // ดึง transaction โบนัสของ user มาจับคู่หาที่มาของโบนัส
        $bonusTxns = \App\Models\Transaction::where('user_id', $user->id)
            ->where('type', 'bonus')
            ->orderBy('created_at', 'desc')
            ->get(['description', 'meta', 'created_at', 'amount']);

        $sourceLabels = [
            'claim_cashback'  => 'รับยอดเสีย (Cashback)',
            'claim_referral'  => 'รับค่าแนะนำเพื่อน',
            'deposit_bonus'   => 'โบนัสฝากเงิน',
            'welcome_bonus'   => 'โบนัสสมาชิกใหม่',
            'spin_reward'     => 'รางวัลกงล้อ',
            'manual'          => 'แอดมินเติมให้',
        ];

        $claims = $user->promotionClaims()
            ->with('promotion:id,title,type,bonus_percent,min_deposit')
            ->orderByRaw("FIELD(status,'active','completed','cancelled','expired')")
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($c) use ($bonusTxns, $sourceLabels) {
                // หา transaction ที่เวลาใกล้เคียงที่สุด (ภายใน 5 วินาที)
                $txn = $bonusTxns->first(fn ($t) =>
                    abs($t->created_at->diffInSeconds($c->created_at)) <= 5
                );

                $metaType = $txn?->meta['type'] ?? null;
                $source   = $sourceLabels[$metaType] ?? null;

                // ลำดับความชัดเจน: โปรโมชันจริง > meta.type > description > type ดิบ
                if ($c->promotion) {
                    $sourceName = $c->promotion->title;
                } elseif ($source) {
                    $sourceName = $source;
                } elseif ($txn?->description) {
                    $sourceName = $txn->description;
                } else {
                    $sourceName = $sourceLabels[$c->type] ?? $c->type;
                }

                return [
                    'id'                  => $c->id,
                    'type'                => $c->type,
                    'source_name'         => $sourceName,
                    'source_detail'       => $txn?->description,
                    'promotion_title'     => $c->promotion?->title,
                    'bonus_amount'        => (float) $c->bonus_amount,
                    'turnover_multiplier' => (float) $c->turnover_multiplier,
                    'turnover_required'   => (float) $c->turnover_required,
                    'turnover_current'    => (float) $c->turnover_current,
                    'remaining'           => (float) $c->remaining,
                    'progress_percent'    => $c->progress_percent,
                    'status'              => $c->status,
                    'is_expired'          => $c->isExpired(),
                    'note'                => $c->note,
                    'expired_at'          => $c->expired_at?->toIso8601String(),
                    'created_at'          => $c->created_at->toIso8601String(),
                ];
            });
            

        // เกมที่เล่นตั้งแต่ได้โบนัสที่ยังค้างอยู่ (นับเฉพาะยอดลงเดิมพัน)
        $activeSince = $user->promotionClaims()->active()->min('created_at');

        $games = collect();
        if ($activeSince) {
            $games = \App\Models\GameLog::where('user_id', $user->id)
                ->where('action', 'bet')
                ->where('created_at', '>=', $activeSince)
                ->select(
                    'provider',
                    'game_id',
                    \DB::raw('COUNT(*) as rounds'),
                    \DB::raw('SUM(bet_amount) as total_bet'),
                    \DB::raw('MAX(created_at) as last_played')
                )
                ->groupBy('provider', 'game_id')
                ->orderByDesc('total_bet')
                ->limit(50)
                ->get();
        }

        // รวมยอดต่อค่าย
        $byProvider = $games->groupBy('provider')->map(fn ($g, $p) => [
            'provider'   => $p,
            'total_bet'  => (float) $g->sum('total_bet'),
            'rounds'     => (int) $g->sum('rounds'),
            'game_count' => $g->count(),
        ])->sortByDesc('total_bet')->values();

        // อ่านอย่างเดียว ไม่ auto-expire (ต่างจาก checkTurnover ที่ใช้ตอนถอน)
        $activeClaims = $user->promotionClaims()->active()->get()
            ->reject(fn ($c) => $c->isExpired());

        return response()->json([
            'status' => 'success',
            'data'   => [
                'has_active'      => $activeClaims->isNotEmpty(),
                'total_remaining' => (float) $activeClaims->sum(fn ($c) => $c->remaining),
                'can_withdraw'    => $activeClaims->isEmpty(),
                'active_since'    => $activeSince,
                'bet_total'       => (float) $games->sum('total_bet'),
                'claims'          => $claims,
                'by_provider'     => $byProvider,
                'games'           => $games,
            ],
        ]);
    }

        /**
     * ช่วงเวลาที่ลูกค้าเล่นเกม (heatmap 7 วัน x 24 ชม.)
     */
    public function playHeatmap(Request $request, User $user): JsonResponse
    {
        $days = min((int) $request->input('days', 90), 365);
        $since = now()->subDays($days)->startOfDay();

        $rows = \App\Models\GameLog::where('user_id', $user->id)
            ->where('action', 'bet')
            ->where('created_at', '>=', $since)
            ->select(
                \DB::raw('DAYOFWEEK(created_at) as dow'),   // 1=อาทิตย์ ... 7=เสาร์
                \DB::raw('HOUR(created_at) as hr'),
                \DB::raw('COUNT(*) as rounds'),
                \DB::raw('SUM(bet_amount) as total_bet')
            )
            ->groupBy('dow', 'hr')
            ->get();

        // สร้างตาราง 7x24 เติม 0 ให้ช่องที่ไม่มีข้อมูล
        $grid = [];
        for ($d = 1; $d <= 7; $d++) {
            for ($h = 0; $h <= 23; $h++) {
                $grid[$d][$h] = ['rounds' => 0, 'total_bet' => 0.0];
            }
        }

        $maxRounds = 0;
        foreach ($rows as $r) {
            $grid[$r->dow][$r->hr] = [
                'rounds'    => (int) $r->rounds,
                'total_bet' => (float) $r->total_bet,
            ];
            $maxRounds = max($maxRounds, (int) $r->rounds);
        }

        $first = \App\Models\GameLog::where('user_id', $user->id)
            ->where('action', 'bet')
            ->where('created_at', '>=', $since)
            ->min('created_at');

        $last = \App\Models\GameLog::where('user_id', $user->id)
            ->where('action', 'bet')
            ->max('created_at');

        return response()->json([
            'status' => 'success',
            'data'   => [
                'grid'         => $grid,
                'max_rounds'   => $maxRounds,
                'total_rounds' => (int) $rows->sum('rounds'),
                'total_bet'    => (float) $rows->sum('total_bet'),
                'first_played' => $first,
                'last_played'  => $last,
                'days'         => $days,
            ],
        ]);
    }

        /**
     * เกมที่ลูกค้าเล่นบ่อย (Top N) พร้อมรูปและชื่อเกม
     */
    public function topGames(Request $request, User $user): JsonResponse
    {
        $limit = min((int) $request->input('limit', 10), 50);
        $sort  = $request->input('sort') === 'bet' ? 'total_bet' : 'rounds';

        $logs = \App\Models\GameLog::where('user_id', $user->id)
            ->where('action', 'bet')
            ->select(
                'provider',
                'game_id',
                \DB::raw('COUNT(*) as rounds'),
                \DB::raw('SUM(bet_amount) as total_bet'),
                \DB::raw('MAX(created_at) as last_played')
            )
            ->groupBy('provider', 'game_id')
            ->orderByDesc($sort)
            ->limit($limit)
            ->get();

        // ดึงชื่อ + รูปเกมมาเติม
        $games = \App\Models\Game::whereIn('game_code', $logs->pluck('game_id'))
            ->get(['product_id', 'game_code', 'game_name', 'game_name_th', 'image_url'])
            ->keyBy(fn ($g) => $g->product_id . '|' . $g->game_code);

        $data = $logs->map(function ($l) use ($games) {
            $g = $games->get($l->provider . '|' . $l->game_id);
            return [
                'provider'    => $l->provider,
                'game_id'     => $l->game_id,
                'game_name'   => $g?->game_name_th ?: ($g?->game_name ?: $l->game_id),
                'image_url'   => $g?->image_url,
                'rounds'      => (int) $l->rounds,
                'total_bet'   => (float) $l->total_bet,
                'last_played' => $l->last_played,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data'   => [
                'games'        => $data,
                'total_rounds' => (int) $logs->sum('rounds'),
                'total_bet'    => (float) $logs->sum('total_bet'),
            ],
        ]);
    }

    /**
     * ยกเลิกเทิร์น + ยกเลิกโบนัส (ไม่หักเครดิตออกจาก wallet)
     */
    public function cancelTurnover(Request $request, User $user, \App\Models\PromotionClaim $claim): JsonResponse
    {
        if ($claim->user_id !== $user->id) {
            return response()->json(['status' => 'error', 'message' => 'ไม่พบรายการเทิร์นของลูกค้ารายนี้'], 404);
        }

        if ($claim->status !== 'active') {
            return response()->json(['status' => 'error', 'message' => 'รายการนี้ไม่ได้อยู่ในสถานะ active'], 400);
        }

        $admin  = $request->user();
        $reason = $request->input('reason');

        $claim->update([
            'status'       => 'cancelled',
            'completed_at' => now(),
            'note'         => trim(($claim->note ? $claim->note . "\n" : '')
                . "ยกเลิกโดย {$admin->name}" . ($reason ? " — {$reason}" : '')),
        ]);

        \Log::info('Turnover cancelled by admin', [
            'claim_id' => $claim->id,
            'user_id'  => $user->id,
            'admin'    => $admin->name,
            'reason'   => $reason,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'ยกเลิกเทิร์นและโบนัสสำเร็จ ลูกค้าถอนได้แล้ว (ไม่ได้หักเครดิต)',
            'data'    => $claim->fresh(),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
    'status'       => 'nullable|in:active,suspended,banned',
    'full_name'    => 'nullable|string|max:100',
    'phone'        => 'nullable|string|max:20',
    'bank_code'    => 'nullable|string|max:20',
    'bank_account' => 'nullable|string|max:30',
    'bank_name'    => 'nullable|string|max:100',
]);

        $user->update(array_filter($data));

        return response()->json([
            'status'  => 'success',
            'message' => 'อัพเดทข้อมูลสำเร็จ',
            'data'    => $user->fresh(),
        ]);
    }

    public function adjustBalance(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'amount'      => 'required|numeric|not_in:0',
            'description' => 'required|string|max:255',
        ]);

        try {
            $transaction = $this->walletService->adjust(
                $user,
                $data['amount'],
                $data['description'],
                $request->user()->id
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'ปรับยอดสำเร็จ',
                'data'    => $transaction,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function adjustTickets(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'amount'      => 'required|integer|not_in:0',
            'description' => 'nullable|string|max:255',
        ]);

        $wallet = $user->wallet;
        if (!$wallet) {
            return response()->json(['status' => 'error', 'message' => 'ไม่พบ wallet'], 404);
        }

        $before = $wallet->ticket_balance;
        if ($data['amount'] > 0) {
            $wallet->increment('ticket_balance', $data['amount']);
        } else {
            if ($before + $data['amount'] < 0) {
                return response()->json(['status' => 'error', 'message' => 'ตั๋วไม่พอหัก'], 400);
            }
            $wallet->decrement('ticket_balance', abs($data['amount']));
        }

        \DB::table('transactions')->insert([
            'user_id' => $user->id,
            'reference_id' => 'TKT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -8)),
            'type' => 'ticket_adjust',
            'direction' => $data['amount'] > 0 ? 'in' : 'out',
            'amount' => abs($data['amount']),
            'balance_before' => $before,
            'balance_after' => $wallet->fresh()->ticket_balance,
            'description' => $data['description'] ?? 'ปรับตั๋ววงล้อ',
            'meta' => json_encode(['adjusted_by' => $request->user()->id]),
            'status' => 'completed',
            'processed_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => "ปรับตั๋วสำเร็จ ({$data['amount']} ใบ)",
            'data'    => ['ticket_balance' => $wallet->fresh()->ticket_balance],
        ]);
    }

    public function adjustPoints(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'amount'      => 'required|integer|not_in:0',
            'description' => 'nullable|string|max:255',
        ]);

        $wallet = $user->wallet;
        if (!$wallet) {
            return response()->json(['status' => 'error', 'message' => 'ไม่พบ wallet'], 404);
        }

        $before = $wallet->point_balance;
        if ($data['amount'] > 0) {
            $wallet->increment('point_balance', $data['amount']);
        } else {
            if ($before + $data['amount'] < 0) {
                return response()->json(['status' => 'error', 'message' => 'คะแนนไม่พอหัก'], 400);
            }
            $wallet->decrement('point_balance', abs($data['amount']));
        }

        \DB::table('transactions')->insert([
            'user_id' => $user->id,
            'reference_id' => 'PNT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -8)),
            'type' => 'point_adjust',
            'direction' => $data['amount'] > 0 ? 'in' : 'out',
            'amount' => abs($data['amount']),
            'balance_before' => $before,
            'balance_after' => $wallet->fresh()->point_balance,
            'description' => $data['description'] ?? 'ปรับคะแนน',
            'meta' => json_encode(['adjusted_by' => $request->user()->id]),
            'status' => 'completed',
            'processed_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => "ปรับคะแนนสำเร็จ ({$data['amount']} คะแนน)",
            'data'    => ['point_balance' => $wallet->fresh()->point_balance],
        ]);
    }

        // =========================================================
    //  🆕 ให้เครดิตฟรี (พร้อมตั้งเทิร์นโอเวอร์)
    // =========================================================

    public function giveBonus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'amount'              => 'required|numeric|min:1',
            'turnover_multiplier' => 'nullable|numeric|min:0',
            'expired_hours'       => 'nullable|integer|min:0',
            'note'                => 'nullable|string|max:255',
        ]);

        try {
            $expiredAt = null;
            if (!empty($data['expired_hours']) && $data['expired_hours'] > 0) {
                $expiredAt = now()->addHours($data['expired_hours']);
            }

            $transaction = $this->walletService->addBonus(
                $user,
                (float) $data['amount'],
                'เครดิตฟรี: ' . ($data['note'] ?? ''),
                [],
                $request->user()->id,
                [
                    'turnover_multiplier' => $data['turnover_multiplier'] ?? null,
                    'type'                => 'free_credit',
                    'expired_at'          => $expiredAt,
                    'note'                => $data['note'] ?? null,
                ],
            );

                        // แจ้ง Telegram
            try {
                $multiplier = $data['turnover_multiplier'] ?? 0;
                $turnover   = (float) $data['amount'] * $multiplier;
                $msg  = "🎁 <b>ให้เครดิตฟรี</b>\n";
                $msg .= "👤 {$user->username}\n";
                $msg .= "💰 " . number_format($data['amount'], 2) . " บาท\n";
                $msg .= "🔄 เทิร์น {$multiplier}x = " . number_format($turnover, 2) . "\n";
                if (!empty($data['note'])) $msg .= "📝 {$data['note']}\n";
                $msg .= "👨‍💼 Admin: " . $request->user()->name;

                app(\App\Services\TelegramService::class)->send($msg);
            } catch (\Exception $e) {
                // ignore
            }

            return response()->json([
                'status'  => 'success',
                'message' => "ให้เครดิตฟรี {$data['amount']} บาท สำเร็จ",
                'data'    => $transaction,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // =========================================================
    // แทรกเพิ่มตรงนี้: ส่วนของการจัดการสิทธิ์และพนักงาน (Admins)
    // =========================================================

    public function getAdmins()
    {
        $admins = Admin::all()->map(function($admin) {
            return [
                'id' => $admin->id,
                'username' => $admin->username,
                'name' => $admin->name,
                'role' => $admin->role,
                'permissions' => json_decode($admin->permissions ?? '[]', true),
            ];
        });
        
        return response()->json($admins);
    }

    public function storeAdmin(Request $request)
    {
        $request->validate([
            'username' => 'required|unique:admins,username',
            'password' => 'required|min:6',
            'name' => 'required|string',
            'role' => 'required|in:super_admin,admin,staff',
            'permissions' => 'nullable|array'
        ]);

        $admin = new Admin();
        $admin->username = $request->username;
        $admin->password = Hash::make($request->password);
        $admin->name = $request->name;
        $admin->role = $request->role;
        $admin->permissions = json_encode($request->permissions ?? []); 
        $admin->save();

        return response()->json(['message' => 'สร้างบัญชีผู้ดูแลระบบสำเร็จ']);
    }

    public function updateAdmin(Request $request, $id)
    {
        $admin = Admin::findOrFail($id);

        $request->validate([
            'username' => 'required|unique:admins,username,' . $id,
            'name' => 'required|string',
            'role' => 'required|in:super_admin,admin,staff',
            'permissions' => 'nullable|array'
        ]);

        $admin->username = $request->username;
        $admin->name = $request->name;
        $admin->role = $request->role;
        $admin->permissions = json_encode($request->permissions ?? []);

        if ($request->filled('password')) {
            $admin->password = Hash::make($request->password);
        }

        $admin->save();

        return response()->json(['message' => 'อัปเดตข้อมูลผู้ดูแลระบบสำเร็จ']);
    }
}