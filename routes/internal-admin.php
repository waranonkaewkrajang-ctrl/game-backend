<?php

/*
| Internal READ-ONLY admin API for the unified admin (NestJS on the LINE VPS).
| Mounted at /api/internal/admin. Every route below re-uses an EXISTING admin controller method unchanged —
| no business logic is copied or rewritten. Write actions (approve/reject/adjust/...) are deliberately absent.
| Auth: AuthenticateInternalService (service token hash in env + optional IP allowlist, GET only).
*/

use App\Http\Controllers\Admin\AdminActivityController;
use App\Http\Controllers\Admin\AdminBankStatementController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminDepositController;
use App\Http\Controllers\Admin\AdminGameCatalogController;
use App\Http\Controllers\Admin\AdminGameController;
use App\Http\Controllers\Admin\AdminPopupController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\Admin\AdminRewardController;
use App\Http\Controllers\Admin\AdminSpinWheelController;
use App\Http\Controllers\Admin\AdminTruewalletController;
use App\Http\Controllers\Admin\AdminUnmatchedDepositController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWithdrawalController;
use App\Http\Controllers\Api\BannerController;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/admin')
    ->middleware(['internal.service', 'throttle:internal-admin'])
    ->group(function () {
        // dashboard / reports
        Route::get('/dashboard',       [AdminDashboardController::class, 'index']);
        Route::get('/reports/daily',   [AdminReportController::class, 'daily']);
        Route::get('/reports/monthly', [AdminReportController::class, 'monthly']);
        Route::get('/reports/profit',  [AdminReportController::class, 'profitLoss']);

        // members
        Route::get('/users',                    [AdminUserController::class, 'index']);
        Route::get('/users/{user}',             [AdminUserController::class, 'show'])->whereNumber('user');
        Route::get('/users/{user}/turnover',    [AdminUserController::class, 'turnover'])->whereNumber('user');
        Route::get('/users/{user}/top-games',   [AdminUserController::class, 'topGames'])->whereNumber('user');
        Route::get('/users/{user}/play-heatmap', [AdminUserController::class, 'playHeatmap'])->whereNumber('user');

        // finance (read only)
        Route::get('/deposits',                [AdminDepositController::class, 'index']);
        Route::get('/withdrawals',             [AdminWithdrawalController::class, 'index']);
        Route::get('/unmatched-deposits',      [AdminUnmatchedDepositController::class, 'index']);
        Route::get('/bank-statements',         [AdminBankStatementController::class, 'index']);
        Route::get('/bank-statements/summary', [AdminBankStatementController::class, 'summary']);
        Route::get('/truewallet/transactions', [AdminTruewalletController::class, 'index']);
        Route::get('/truewallet/summary',      [AdminTruewalletController::class, 'summary']);

        // games
        Route::get('/games',                  [AdminGameController::class, 'index']);
        Route::get('/game-catalog/providers', [AdminGameCatalogController::class, 'providers']);

        // promotions & engagement
        Route::get('/rewards',             [AdminRewardController::class, 'index']);
        Route::get('/rewards/summary',     [AdminRewardController::class, 'summary']);
        Route::get('/spin-wheel/history',  [AdminSpinWheelController::class, 'history']);
        Route::get('/spin-wheel/summary',  [AdminSpinWheelController::class, 'summary']);
        Route::get('/banners',             [BannerController::class, 'adminIndex']);
        Route::get('/popups',              [AdminPopupController::class, 'index']);
        Route::get('/activities',          [AdminActivityController::class, 'index']);

        // 🆕 CRM กฎ C — รายงานกลุ่มตามยอดการเงิน (อ่านอย่างเดียว, เรียกเมื่อแอดมินเปิดดู)
        Route::get('/crm/segments/withdraw-gt-deposit', [\App\Http\Controllers\Internal\CrmSegmentController::class, 'withdrawGtDeposit']);
    });
