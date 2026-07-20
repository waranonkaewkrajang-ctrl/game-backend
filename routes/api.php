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

// =====================================================
//  PUBLIC ROUTES
// =====================================================
Route::prefix('auth')->group(function () {
    Route::post('/register',   [AuthController::class, 'register']);
    Route::post('/login',      [AuthController::class, 'login']);
    Route::post('/verify-2fa', [AuthController::class, 'verifyTwoFactor']);
});

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
            'data'   => $query->orderBy('rank')->limit(200)->get(),
        ]);
    });

    Route::post('/games/launch',      [GameController::class, 'launchGame']);
    Route::get('/games/history',      [GameController::class, 'history']);
    Route::post('/games/launch',      [GameController::class, 'launchGame']);
    Route::get('/games/history',      [GameController::class, 'history']);
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

        // เพิ่ม Route สำหรับจัดการแอดมิน
        Route::get('/admins', [AdminUserController::class, 'getAdmins']); // ดูรายชื่อแอดมิน
        Route::post('/admins', [AdminUserController::class, 'storeAdmin']); // เพิ่มแอดมิน
        Route::put('/admins/{id}', [AdminUserController::class, 'updateAdmin']); // แก้ไขแอดมิน

        // Users
        Route::get('/users',                [AdminUserController::class, 'index']);
        Route::get('/users/{user}',         [AdminUserController::class, 'show']);
        Route::put('/users/{user}',         [AdminUserController::class, 'update']);
        Route::post('/users/{user}/adjust', [AdminUserController::class, 'adjustBalance']);

        // Deposits
        Route::get('/deposits',                    [AdminDepositController::class, 'index']);
        Route::post('/deposits/{deposit}/approve', [AdminDepositController::class, 'approve']);
        Route::post('/deposits/{deposit}/reject',  [AdminDepositController::class, 'reject']);

        // Withdrawals
        Route::get('/withdrawals',                       [AdminWithdrawalController::class, 'index']);
        Route::post('/withdrawals/{withdrawal}/approve', [AdminWithdrawalController::class, 'approve']);
        Route::post('/withdrawals/{withdrawal}/reject',  [AdminWithdrawalController::class, 'reject']);

        // Promotions
        Route::apiResource('/promotions', AdminPromotionController::class);

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

        // Game Management (Admin)
        Route::get('/games',                  [\App\Http\Controllers\Admin\AdminGameController::class, 'index']);
        Route::get('/games/products',         [\App\Http\Controllers\Admin\AdminGameController::class, 'getProducts']);
        Route::post('/games/sync',            [\App\Http\Controllers\Admin\AdminGameController::class, 'syncGames']);
        Route::post('/games/{game}/toggle',   [\App\Http\Controllers\Admin\AdminGameController::class, 'toggleGame']);
        Route::post('/games/toggle-product',  [\App\Http\Controllers\Admin\AdminGameController::class, 'toggleProduct']);
        Route::get('/games/agent-credit',     [\App\Http\Controllers\Admin\AdminGameController::class, 'agentCredit']);

    });
});