<?php

/*
| Internal admin API for the unified admin (NestJS on the LINE VPS). Mounted at /api/internal/admin.
|
| Every route re-uses an EXISTING admin controller method unchanged (same validation, same services, same
| money logic, same locking). Old-admin routes written as closures are copied 1:1 into
| Internal\InternalAdminController (closures cannot be shared).
|
| Auth (AuthenticateInternalService): service token hash in env + IP allowlist.
| READS  : GET only.
| WRITES : only when INTERNAL_ADMIN_WRITES_ENABLED=true, and only routes in the write group below:
|          internal.actor      → X-Acting-Admin-Id must be an active game admin; becomes $request->user()
|          internal.idempotent → Idempotency-Key required; a repeated key returns the stored result
|
| Deliberately NOT exposed: API token management (credentials), bank-incoming ingest/resume (bot only),
| marketing/export (bulk personal data), truewallet match/ignore, spin-wheel give-tickets,
| adjust-tickets/points, unmatched-deposits store — not used by the unified admin pages.
*/

use App\Http\Controllers\Admin\AdminActivityController;
use App\Http\Controllers\Admin\AdminBankIncomingController;
use App\Http\Controllers\Admin\AdminBankStatementController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminDepositController;
use App\Http\Controllers\Admin\AdminGameCatalogController;
use App\Http\Controllers\Admin\AdminGameController;
use App\Http\Controllers\Admin\AdminMarketingController;
use App\Http\Controllers\Admin\AdminPopupController;
use App\Http\Controllers\Admin\AdminPromotionController;
use App\Http\Controllers\Admin\AdminRankController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\Admin\AdminRewardController;
use App\Http\Controllers\Admin\AdminSpinWheelController;
use App\Http\Controllers\Admin\AdminStuckBetController;
use App\Http\Controllers\Admin\AdminThemeController;
use App\Http\Controllers\Admin\AdminTopbarController;
use App\Http\Controllers\Admin\AdminTruewalletController;
use App\Http\Controllers\Admin\AdminUnmatchedDepositController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWithdrawalController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Internal\CrmSegmentController;
use App\Http\Controllers\Internal\InternalAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/admin')
    ->middleware(['internal.service', 'throttle:internal-admin'])
    ->group(function () {
        // ================= READS =================
        // dashboard / reports
        Route::get('/dashboard',       [AdminDashboardController::class, 'index']);
        Route::get('/topbar',          [AdminTopbarController::class, 'index']);
        Route::get('/reports/daily',   [AdminReportController::class, 'daily']);
        Route::get('/reports/monthly', [AdminReportController::class, 'monthly']);
        Route::get('/reports/profit',  [AdminReportController::class, 'profitLoss']);
        Route::get('/marketing/users', [AdminMarketingController::class, 'users']);

        // members
        Route::get('/users',                    [AdminUserController::class, 'index']);
        Route::get('/users/{user}',             [AdminUserController::class, 'show'])->whereNumber('user');
        Route::get('/users/{user}/turnover',    [AdminUserController::class, 'turnover'])->whereNumber('user');
        Route::get('/users/{user}/top-games',   [AdminUserController::class, 'topGames'])->whereNumber('user');
        Route::get('/users/{user}/play-heatmap', [AdminUserController::class, 'playHeatmap'])->whereNumber('user');
        Route::get('/users/{id}/stuck-bets',    [AdminStuckBetController::class, 'byUser'])->whereNumber('id');
        Route::get('/bank-changes',             [InternalAdminController::class, 'bankChanges']);
        Route::get('/referrals',                [InternalAdminController::class, 'referrals']);
        Route::get('/ip-check',                 [InternalAdminController::class, 'ipCheck']);

        // finance
        Route::get('/deposits',                [AdminDepositController::class, 'index']);
        Route::get('/withdrawals',             [AdminWithdrawalController::class, 'index']);
        Route::get('/unmatched-deposits',      [AdminUnmatchedDepositController::class, 'index']);
        Route::get('/transactions',            [InternalAdminController::class, 'transactions']);
        Route::get('/bank-statements',         [AdminBankStatementController::class, 'index']);
        Route::get('/bank-statements/summary', [AdminBankStatementController::class, 'summary']);
        Route::get('/bank-incoming',           [AdminBankIncomingController::class, 'index']);
        Route::get('/bank-incoming/status',    [AdminBankIncomingController::class, 'status']);
        Route::get('/truewallet/transactions', [AdminTruewalletController::class, 'index']);
        Route::get('/truewallet/summary',      [AdminTruewalletController::class, 'summary']);
        Route::get('/truewallet/unmatched',    [AdminTruewalletController::class, 'unmatched']);

        // games
        Route::get('/games',                  [AdminGameController::class, 'index']);
        Route::get('/games/products',         [AdminGameController::class, 'getProducts']);
        Route::get('/games/product-images',   [AdminGameController::class, 'getProductImages']);
        Route::get('/games/agent-credit',     [AdminGameController::class, 'agentCredit']);
        Route::get('/games/bet-records',      [GameController::class, 'betRecords']);
        Route::get('/game-logs',              [InternalAdminController::class, 'gameLogs']);
        Route::get('/game-catalog/providers', [AdminGameCatalogController::class, 'providers']);
        Route::get('/game-catalog/games',     [AdminGameCatalogController::class, 'games']);

        // promotions & engagement
        Route::get('/promotions',            [AdminPromotionController::class, 'index']);
        Route::get('/promotions/{promotion}', [AdminPromotionController::class, 'show'])->whereNumber('promotion');
        Route::get('/rewards',               [AdminRewardController::class, 'index']);
        Route::get('/rewards/summary',       [AdminRewardController::class, 'summary']);
        Route::get('/rewards/by-user',       [AdminRewardController::class, 'byUser']);
        Route::get('/rewards/{id}',          [AdminRewardController::class, 'show'])->whereNumber('id');
        Route::get('/spin-wheel/history',    [AdminSpinWheelController::class, 'history']);
        Route::get('/spin-wheel/summary',    [AdminSpinWheelController::class, 'summary']);
        Route::get('/spin-wheel/prizes',     [AdminSpinWheelController::class, 'prizes']);
        Route::get('/spin-wheel/settings',   [AdminSpinWheelController::class, 'settings']);
        Route::get('/spin-wheel/multipliers', [AdminSpinWheelController::class, 'multipliers']);
        Route::get('/banners',               [BannerController::class, 'adminIndex']);
        Route::get('/popups',                [AdminPopupController::class, 'index']);
        Route::get('/activities',            [AdminActivityController::class, 'index']);

        // settings & staff
        Route::get('/settings', [InternalAdminController::class, 'settings']); // secrets masked
        Route::get('/theme',    [AdminThemeController::class, 'show']);
        Route::get('/admins',   [AdminUserController::class, 'getAdmins']);   // id/username/name/role/permissions only

        // outcome of a write after a timeout (by Idempotency-Key)
        Route::get('/operations/{key}', [InternalAdminController::class, 'operation'])->where('key', '[A-Za-z0-9_\-]{16,100}');

        // CRM rule C (read only)
        Route::get('/crm/segments/withdraw-gt-deposit', [CrmSegmentController::class, 'withdrawGtDeposit']);

        // ================= WRITES (real game admin + Idempotency-Key) =================
        Route::middleware(['internal.actor', 'internal.idempotent'])->group(function () {
            // finance — same controllers/services as the old admin (row lock + status check inside)
            Route::post('/deposits/{deposit}/approve',                    [AdminDepositController::class, 'approve'])->whereNumber('deposit');
            Route::post('/deposits/{deposit}/reject',                     [AdminDepositController::class, 'reject'])->whereNumber('deposit');
            Route::post('/withdrawals/{withdrawal}/approve',              [AdminWithdrawalController::class, 'approve'])->whereNumber('withdrawal');
            Route::post('/withdrawals/{withdrawal}/reject',               [AdminWithdrawalController::class, 'reject'])->whereNumber('withdrawal');
            Route::post('/withdrawals/{withdrawal}/lock',                 [AdminWithdrawalController::class, 'lock'])->whereNumber('withdrawal');
            Route::post('/withdrawals/{withdrawal}/unlock',               [AdminWithdrawalController::class, 'unlock'])->whereNumber('withdrawal');
            Route::post('/unmatched-deposits/{unmatchedDeposit}/approve', [AdminUnmatchedDepositController::class, 'approve'])->whereNumber('unmatchedDeposit');
            Route::post('/unmatched-deposits/{unmatchedDeposit}/reject',  [AdminUnmatchedDepositController::class, 'reject'])->whereNumber('unmatchedDeposit');

            // members
            Route::post('/users',                                 [AdminUserController::class, 'store']);
            Route::put('/users/{user}',                           [AdminUserController::class, 'update'])->whereNumber('user');
            Route::post('/users/{user}/adjust',                   [AdminUserController::class, 'adjustBalance'])->whereNumber('user');
            Route::post('/users/{user}/bonus',                    [AdminUserController::class, 'giveBonus'])->whereNumber('user');
            Route::post('/users/{user}/turnover/{claim}/cancel',  [AdminUserController::class, 'cancelTurnover'])->whereNumber(['user', 'claim']);
            Route::post('/users/{user}/reset-password',           [InternalAdminController::class, 'resetMemberPassword'])->whereNumber('user');
            Route::post('/bank-changes/{id}/approve',             [InternalAdminController::class, 'approveBankChange'])->whereNumber('id');
            Route::post('/bank-changes/{id}/reject',              [InternalAdminController::class, 'rejectBankChange'])->whereNumber('id');

            // games
            Route::post('/games/sync',                 [AdminGameController::class, 'syncGames']);
            Route::post('/games/{game}/toggle',        [AdminGameController::class, 'toggleGame'])->whereNumber('game');
            Route::post('/games/toggle-product',       [AdminGameController::class, 'toggleProduct']);
            Route::post('/games/product-image',        [AdminGameController::class, 'updateProductImage']);
            Route::post('/game-catalog/games-by-keys', [AdminGameCatalogController::class, 'gamesByKeys']); // read by POST body

            // promotions & engagement
            Route::post('/promotions',                    [AdminPromotionController::class, 'store']);
            Route::post('/promotions/upload-image',       [AdminPromotionController::class, 'uploadImage']);
            Route::put('/promotions/{promotion}',         [AdminPromotionController::class, 'update'])->whereNumber('promotion');
            Route::delete('/promotions/{promotion}',      [AdminPromotionController::class, 'destroy'])->whereNumber('promotion');
            Route::post('/popups',                        [AdminPopupController::class, 'store']);
            Route::post('/popups/upload-image',           [AdminPopupController::class, 'uploadImage']);
            Route::put('/popups/{popup}',                 [AdminPopupController::class, 'update'])->whereNumber('popup');
            Route::delete('/popups/{popup}',              [AdminPopupController::class, 'destroy'])->whereNumber('popup');
            Route::post('/popups/{popup}/toggle',         [AdminPopupController::class, 'toggle'])->whereNumber('popup');
            Route::post('/activities',                    [AdminActivityController::class, 'store']);
            Route::post('/activities/upload-image',       [AdminActivityController::class, 'uploadImage']);
            Route::post('/activities/reorder',            [AdminActivityController::class, 'reorder']);
            Route::put('/activities/{id}',                [AdminActivityController::class, 'update'])->whereNumber('id');
            Route::post('/activities/{id}/toggle',        [AdminActivityController::class, 'toggle'])->whereNumber('id');
            Route::delete('/activities/{id}',             [AdminActivityController::class, 'destroy'])->whereNumber('id');
            Route::post('/banners',                       [BannerController::class, 'store']);
            Route::post('/banners/upload-image',          [BannerController::class, 'uploadImage']);
            Route::delete('/banners/{id}',                [BannerController::class, 'destroy'])->whereNumber('id');
            Route::post('/spin-wheel/prizes',             [AdminSpinWheelController::class, 'storePrize']);
            Route::put('/spin-wheel/prizes/{id}',         [AdminSpinWheelController::class, 'updatePrize'])->whereNumber('id');
            Route::delete('/spin-wheel/prizes/{id}',      [AdminSpinWheelController::class, 'destroyPrize'])->whereNumber('id');
            Route::post('/spin-wheel/upload-image',       [AdminSpinWheelController::class, 'uploadImage']);
            Route::post('/spin-wheel/settings',           [AdminSpinWheelController::class, 'updateSettings']);
            Route::post('/spin-wheel/multipliers',        [AdminSpinWheelController::class, 'storeMultiplier']);
            Route::put('/spin-wheel/multipliers/{id}',    [AdminSpinWheelController::class, 'updateMultiplier'])->whereNumber('id');
            Route::delete('/spin-wheel/multipliers/{id}', [AdminSpinWheelController::class, 'destroyMultiplier'])->whereNumber('id');
            Route::post('/ranks/upload-image',            [AdminRankController::class, 'uploadImage']);

            // settings & staff
            Route::post('/settings',                       [InternalAdminController::class, 'updateSettings']); // masked secrets kept
            Route::post('/settings/telegram-test',         [InternalAdminController::class, 'telegramTest']);
            Route::post('/settings/telegram-fetch-chats',  [InternalAdminController::class, 'telegramFetchChats']);
            Route::put('/theme',                           [AdminThemeController::class, 'update']);
            Route::post('/admins',                         [AdminUserController::class, 'storeAdmin']);
            Route::put('/admins/{id}',                     [AdminUserController::class, 'updateAdmin'])->whereNumber('id');
        });
    });
