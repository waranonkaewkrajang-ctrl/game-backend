<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\WithdrawalController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminDepositController;
use App\Http\Controllers\Admin\AdminWithdrawalController;
use App\Http\Controllers\Admin\AdminPromotionController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\Api\BannerController;

// API สำหรับฝั่งลูกค้า (ไม่ต้องล็อคอินแอดมินก็ดึงได้)
Route::get('/banners', [BannerController::class, 'index']);

Route::get('/maintenance/check', function () {
    $mode = \App\Models\Setting::where('key', 'maintenance_mode')->value('value');
    return response()->json(['maintenance' => $mode === 'true']);
});

// =====================================================
//  PUBLIC ROUTES
// =====================================================
Route::prefix('auth')->group(function () {
    Route::post('/register',   [AuthController::class, 'register']);
    Route::post('/login',      [AuthController::class, 'login']);
    Route::post('/verify-2fa', [AuthController::class, 'verifyTwoFactor']);
});

// =====================================================
//  TRUEWALLET WEBHOOK
// =====================================================
Route::post('/truewallet/webhook', [\App\Http\Controllers\Api\TrueWalletWebhookController::class, 'webhook']);


// =====================================================
//  GAME CALLBACK (ค่ายเกม AMB เรียกมา — Seamless Wallet)
// =====================================================
Route::prefix('game/callback')->group(function () {
    Route::post('/checkBalance', [GameController::class, 'getBalance']);
    Route::post('/placeBets',    [GameController::class, 'bet']);
    Route::post('/settleBets',   [GameController::class, 'win']);
});

// =====================================================
//  USER ROUTES (ต้อง login)
// =====================================================
Route::middleware('auth:sanctum')->group(function () {

    // Auth & 2FA
    Route::post('/auth/logout',      [AuthController::class, 'logout']);
    Route::get('/auth/me',           [AuthController::class, 'me']);
    Route::post('/auth/2fa/enable',  [AuthController::class, 'enableTwoFactor']);
    Route::post('/auth/2fa/confirm', [AuthController::class, 'confirmTwoFactor']);
    Route::post('/auth/2fa/disable', [AuthController::class, 'disableTwoFactor']);

    // Wallet
    Route::get('/wallet/balance',      [WalletController::class, 'balance']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);

    // Deposit
    Route::post('/deposits',          [DepositController::class, 'store']);
    Route::get('/deposits',           [DepositController::class, 'index']);
    Route::get('/deposits/{deposit}', [DepositController::class, 'show']);

    // Withdrawal
    Route::post('/withdrawals',              [WithdrawalController::class, 'store']);
    Route::get('/withdrawals',               [WithdrawalController::class, 'index']);
    Route::get('/withdrawals/{withdrawal}',  [WithdrawalController::class, 'show']);

    // วงล้อ
    Route::get('/spin-wheel',         [\App\Http\Controllers\Api\SpinWheelController::class, 'index']);
    Route::post('/spin-wheel/spin',   [\App\Http\Controllers\Api\SpinWheelController::class, 'spin']);
    Route::get('/spin-wheel/history', [\App\Http\Controllers\Api\SpinWheelController::class, 'history']);
    Route::get('/spin-wheel/recent-winners', [\App\Http\Controllers\Api\SpinWheelController::class, 'recentWinners']);

    // Promotions
    Route::get('/promotions',                        [PromotionController::class, 'index']);
    Route::get('/promotions/{promotion}',            [PromotionController::class, 'show']);
    Route::post('/promotions/{promotion}/claim',     [PromotionController::class, 'claim']);

    // Game
    Route::get('/games/products', function () {
        $products = \App\Models\Game::where('is_active', true)
            ->select('product_id')
            ->distinct()
            ->pluck('product_id');
        return response()->json(['status' => 'success', 'data' => $products]);
    });

    Route::get('/games', function (\Illuminate\Http\Request $request) {
        $query = \App\Models\Game::where('is_active', true);
        if ($request->filled('productId')) {
            $query->where('product_id', $request->productId);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('game_name', 'like', "%{$search}%")
                  ->orWhere('game_name_th', 'like', "%{$search}%");
            });
        }
        return response()->json([
            'status' => 'success',
            'data'   => $query->orderBy('rank')->limit(50)->get(),
        ]);
    });

    Route::post('/games/launch',      [GameController::class, 'launchGame']);
    Route::get('/games/history',      [GameController::class, 'history']);
    Route::post('/games/launch',      [GameController::class, 'launchGame']);
    Route::get('/games/history',      [GameController::class, 'history']);
    Route::get('/games/product-images', [\App\Http\Controllers\Admin\AdminGameController::class, 'getProductImages']);

    // === Rewards (ยอดเสีย + แนะนำเพื่อน) ===
    Route::get('/rewards/summary', [\App\Http\Controllers\Api\RewardController::class, 'summary']);
    Route::post('/rewards/claim/cashback', [\App\Http\Controllers\Api\RewardController::class, 'claimCashback']);
    Route::post('/rewards/claim/referral', [\App\Http\Controllers\Api\RewardController::class, 'claimReferral']);
    Route::get('/rewards/history', [\App\Http\Controllers\Api\RewardController::class, 'history']);

    // === User Rank ===
    Route::get('/user/rank', function (\Illuminate\Http\Request $request) {
        $user = $request->user();
        
        $totalDeposit = (float) \App\Models\Deposit::where('user_id', $user->id)
            ->where('status', 'approved')
            ->sum('amount');

        $ranks = json_decode(\App\Models\Setting::getValue('ranks', '[]'), true) ?: [];
        
        usort($ranks, fn($a, $b) => ($b['min_deposit'] ?? 0) - ($a['min_deposit'] ?? 0));

        $currentRank = null;
        $nextRank = null;

        foreach ($ranks as $rank) {
            if ($totalDeposit >= ($rank['min_deposit'] ?? 0)) {
                $currentRank = $rank;
                break;
            }
        }

        if ($currentRank) {
            $ranks2 = json_decode(\App\Models\Setting::getValue('ranks', '[]'), true) ?: [];
            usort($ranks2, fn($a, $b) => ($a['min_deposit'] ?? 0) - ($b['min_deposit'] ?? 0));
            foreach ($ranks2 as $r) {
                if (($r['min_deposit'] ?? 0) > ($currentRank['min_deposit'] ?? 0)) {
                    $nextRank = $r;
                    break;
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_deposit' => $totalDeposit,
                'current_rank' => $currentRank,
                'next_rank' => $nextRank,
                'progress' => $nextRank
                    ? min(100, round(($totalDeposit / ($nextRank['min_deposit'] ?? 1)) * 100, 1))
                    : 100,
            ],
        ]);
    });

    // === Finance Settings (ลูกค้าดึงค่าตั้งการเงิน) ===
    Route::get('/finance/settings', function () {
        $keys = ['min_deposit', 'max_deposit', 'min_withdraw', 'max_withdraw', 'deposit_banks', 'deposit_channels', 'deposit_amounts', 'truewallet_accounts'];
        $settings = \App\Models\Setting::whereIn('key', $keys)->pluck('value', 'key');

        $banks = json_decode($settings['deposit_banks'] ?? '[]', true) ?: [];
        $channels = json_decode($settings['deposit_channels'] ?? '["bank_transfer","promptpay","truewallet"]', true) ?: ['bank_transfer', 'promptpay', 'truewallet'];
        $amounts = json_decode($settings['deposit_amounts'] ?? '[100,300,500,1000,5000]', true) ?: [100, 300, 500, 1000, 5000];

        $activeBanks = array_values(array_filter($banks, fn($b) => $b['is_active'] ?? false));

        return response()->json([
            'status' => 'success',
            'data' => [
                'min_deposit'  => (float) ($settings['min_deposit'] ?? 100),
                'max_deposit'  => (float) ($settings['max_deposit'] ?? 200000),
                'min_withdraw' => (float) ($settings['min_withdraw'] ?? 300),
                'max_withdraw' => (float) ($settings['max_withdraw'] ?? 200000),
                'banks'        => $activeBanks,
                'channels'     => $channels,
                'amounts'      => $amounts,
                'truewallet_accounts' => json_decode($settings['truewallet_accounts'] ?? '[]', true) ?: [],
                'ranks' => json_decode(\App\Models\Setting::getValue('ranks', '[]'), true) ?: [],
            ],
        ]);
    });
});

// =====================================================
//  ADMIN ROUTES
// =====================================================
Route::prefix('admin')->group(function () {

    Route::post('/login',      [AdminAuthController::class, 'login']);
    Route::post('/verify-2fa', [AdminAuthController::class, 'verifyTwoFactor']);

    Route::middleware('auth:sanctum')->group(function () {

        // Auth & 2FA
        Route::post('/logout',      [AdminAuthController::class, 'logout']);
        Route::get('/me',           [AdminAuthController::class, 'me']);
        Route::post('/2fa/enable',  [AdminAuthController::class, 'enableTwoFactor']);
        Route::post('/2fa/confirm', [AdminAuthController::class, 'confirmTwoFactor']);

        // Dashboard
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);

        // 🟢 นำ Route แบนเนอร์มาวางไว้ตรงนี้ครับ 🟢
        Route::get('/banners', [BannerController::class, 'adminIndex']);
        Route::post('/banners/upload-image', [BannerController::class, 'uploadImage']);
        Route::post('/banners', [BannerController::class, 'store']);
        Route::delete('/banners/{id}', [BannerController::class, 'destroy']);

        // Unmatched Deposits (ยอดค้าง)
        Route::get('/unmatched-deposits', [\App\Http\Controllers\Admin\AdminUnmatchedDepositController::class, 'index']);
        Route::post('/unmatched-deposits', [\App\Http\Controllers\Admin\AdminUnmatchedDepositController::class, 'store']);
        Route::post('/unmatched-deposits/{unmatchedDeposit}/approve', [\App\Http\Controllers\Admin\AdminUnmatchedDepositController::class, 'approve']);
        Route::post('/unmatched-deposits/{unmatchedDeposit}/reject', [\App\Http\Controllers\Admin\AdminUnmatchedDepositController::class, 'reject']);

        // เพิ่ม Route สำหรับจัดการแอดมิน
        Route::get('/admins', [AdminUserController::class, 'getAdmins']); // ดูรายชื่อแอดมิน
        Route::post('/admins', [AdminUserController::class, 'storeAdmin']); // เพิ่มแอดมิน
        Route::put('/admins/{id}', [AdminUserController::class, 'updateAdmin']); // แก้ไขแอดมิน

        // Users
        Route::get('/users',                [AdminUserController::class, 'index']);
        Route::get('/users/{user}',         [AdminUserController::class, 'show']);
        Route::put('/users/{user}',         [AdminUserController::class, 'update']);
        Route::post('/users/{user}/adjust', [AdminUserController::class, 'adjustBalance']);
        Route::post('/users/{user}/adjust-tickets', [AdminUserController::class, 'adjustTickets']);
        Route::post('/users/{user}/adjust-points', [AdminUserController::class, 'adjustPoints']);

        // Transactions
        Route::get('/transactions', function (\Illuminate\Http\Request $request) {
            $query = \DB::table('transactions')
                ->join('users', 'transactions.user_id', '=', 'users.id')
                ->select('transactions.*', 'users.username', 'users.phone')
                ->orderBy('transactions.created_at', 'desc');
            if ($request->filled('type')) {
                $query->where('transactions.type', $request->type);
            }
            if ($request->filled('search')) {
                $s = $request->search;
                $query->where(function ($q) use ($s) {
                    $q->where('users.username', 'like', "%{$s}%")
                      ->orWhere('users.phone', 'like', "%{$s}%")
                      ->orWhere('transactions.reference_id', 'like', "%{$s}%");
                });
            }
            return response()->json([
                'status' => 'success',
                'data' => $query->paginate(20),
            ]);
        });

        // Deposits

        // Deposits
        Route::get('/deposits',                    [AdminDepositController::class, 'index']);
        Route::post('/deposits/{deposit}/approve', [AdminDepositController::class, 'approve']);
        Route::post('/deposits/{deposit}/reject',  [AdminDepositController::class, 'reject']);

        // Withdrawals
        Route::get('/withdrawals',                       [AdminWithdrawalController::class, 'index']);
        Route::post('/withdrawals/{withdrawal}/approve', [AdminWithdrawalController::class, 'approve']);
        Route::post('/withdrawals/{withdrawal}/reject',  [AdminWithdrawalController::class, 'reject']);
        Route::post('/withdrawals/{withdrawal}/lock',   [AdminWithdrawalController::class, 'lock']);
        Route::post('/withdrawals/{withdrawal}/unlock', [AdminWithdrawalController::class, 'unlock']);

        // Promotions
        Route::apiResource('/promotions', AdminPromotionController::class);
        Route::post('/promotions/upload-image', [AdminPromotionController::class, 'uploadImage']);

        // Reports
        Route::get('/reports/daily',   [AdminReportController::class, 'daily']);
        Route::get('/reports/monthly', [AdminReportController::class, 'monthly']);
        Route::get('/reports/profit',  [AdminReportController::class, 'profitLoss']);

        // Settings
        Route::get('/settings', function () {
            return response()->json(\App\Models\Setting::pluck('value', 'key'));
        });
        Route::post('/settings', function (\Illuminate\Http\Request $request) {
            foreach ($request->all() as $key => $value) {
                \App\Models\Setting::updateOrCreate(['key' => $key], ['value' => $value]);
            }
            return response()->json(['message' => 'success']);
        });

        Route::post('/settings/telegram-test', function (\Illuminate\Http\Request $request) {
            $service = new \App\Services\TelegramService();
            $result = $service->sendTest($request->bot_token, $request->chat_id);
            if ($result) {
                return response()->json(['status' => 'success', 'message' => 'ส่งสำเร็จ']);
            }
            return response()->json(['status' => 'error', 'message' => 'ส่งไม่สำเร็จ เช็ค Token กับ Chat ID'], 400);
        });

        // Game (Admin) — AMB Seamless
        Route::get('/games/agent-credit', [GameController::class, 'agentCredit']);
        Route::get('/games/bet-records',  [GameController::class, 'betRecords']);

        // Referrals (ระบบแนะนำเพื่อน)
        Route::get('/referrals', function (\Illuminate\Http\Request $request) {
            $query = \App\Models\User::withCount(['referrals as referred_count'])
                ->orderBy('referred_count', 'desc');
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where('username', 'like', "%{$search}%")
                      ->orWhere('referral_code', 'like', "%{$search}%");
            }
            return response()->json(['data' => $query->paginate(50)]);
        });

        // Game Logs (ประวัติเดิมพัน)
        Route::get('/game-logs', function (\Illuminate\Http\Request $request) {
            $query = \App\Models\GameLog::with('user')->orderBy('created_at', 'desc');
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where('game_name', 'like', "%{$search}%")
                      ->orWhere('round_id', 'like', "%{$search}%")
                      ->orWhereHas('user', function ($u) use ($search) {
                          $u->where('username', 'like', "%{$search}%");
                      });
            }
            return response()->json(['data' => $query->paginate(50)]);
        });

        // IP Check (ตรวจสอบ IP)
        Route::get('/ip-check', function (\Illuminate\Http\Request $request) {
            $query = \App\Models\User::whereNotNull('last_login_ip')->orderBy('last_login_at', 'desc');
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where('username', 'like', "%{$search}%")
                      ->orWhere('last_login_ip', 'like', "%{$search}%");
            }
            return response()->json(['data' => $query->paginate(50)]);
        });

        // TrueWallet เดินบัญชี
        Route::get('/truewallet/transactions', [\App\Http\Controllers\Admin\AdminTruewalletController::class, 'index']);
        Route::get('/truewallet/summary',      [\App\Http\Controllers\Admin\AdminTruewalletController::class, 'summary']);
        Route::get('/truewallet/unmatched',     [\App\Http\Controllers\Admin\AdminTruewalletController::class, 'unmatched']);
        Route::post('/truewallet/{id}/match',   [\App\Http\Controllers\Admin\AdminTruewalletController::class, 'match']);
        Route::post('/truewallet/{id}/ignore',  [\App\Http\Controllers\Admin\AdminTruewalletController::class, 'ignore']);

        // วงล้อ (Admin)
        Route::get('/spin-wheel/prizes',           [\App\Http\Controllers\Admin\AdminSpinWheelController::class, 'prizes']);
        Route::post('/spin-wheel/prizes',          [\App\Http\Controllers\Admin\AdminSpinWheelController::class, 'storePrize']);
        Route::put('/spin-wheel/prizes/{id}',      [\App\Http\Controllers\Admin\AdminSpinWheelController::class, 'updatePrize']);
        Route::delete('/spin-wheel/prizes/{id}',   [\App\Http\Controllers\Admin\AdminSpinWheelController::class, 'destroyPrize']);
        Route::get('/spin-wheel/settings',         [\App\Http\Controllers\Admin\AdminSpinWheelController::class, 'settings']);
        Route::post('/spin-wheel/settings',        [\App\Http\Controllers\Admin\AdminSpinWheelController::class, 'updateSettings']);
        Route::get('/spin-wheel/history',          [\App\Http\Controllers\Admin\AdminSpinWheelController::class, 'history']);
        Route::get('/spin-wheel/summary',          [\App\Http\Controllers\Admin\AdminSpinWheelController::class, 'summary']);
        Route::get('/spin-wheel/multipliers',          [\App\Http\Controllers\Admin\AdminSpinWheelController::class, 'multipliers']);
        Route::post('/spin-wheel/multipliers',         [\App\Http\Controllers\Admin\AdminSpinWheelController::class, 'storeMultiplier']);
        Route::put('/spin-wheel/multipliers/{id}',     [\App\Http\Controllers\Admin\AdminSpinWheelController::class, 'updateMultiplier']);
        Route::delete('/spin-wheel/multipliers/{id}',  [\App\Http\Controllers\Admin\AdminSpinWheelController::class, 'destroyMultiplier']);
        Route::post('/spin-wheel/give-tickets',        [\App\Http\Controllers\Admin\AdminSpinWheelController::class, 'giveTickets']);

        // Game Management (Admin)
        Route::get('/games',                  [\App\Http\Controllers\Admin\AdminGameController::class, 'index']);
        Route::get('/games/products',         [\App\Http\Controllers\Admin\AdminGameController::class, 'getProducts']);
        Route::post('/games/sync',            [\App\Http\Controllers\Admin\AdminGameController::class, 'syncGames']);
        Route::post('/games/{game}/toggle',   [\App\Http\Controllers\Admin\AdminGameController::class, 'toggleGame']);
        Route::post('/games/toggle-product',  [\App\Http\Controllers\Admin\AdminGameController::class, 'toggleProduct']);
        Route::get('/games/agent-credit',     [\App\Http\Controllers\Admin\AdminGameController::class, 'agentCredit']);
        Route::get('/games/product-images',   [\App\Http\Controllers\Admin\AdminGameController::class, 'getProductImages']);
        Route::post('/games/product-image',   [\App\Http\Controllers\Admin\AdminGameController::class, 'updateProductImage']);

    });
});