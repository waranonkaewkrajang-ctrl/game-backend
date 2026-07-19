<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminReportController extends Controller
{
    public function daily(Request $request): JsonResponse
    {
        $date = $request->input('date', now()->toDateString());
        $startOfDay = \Carbon\Carbon::parse($date)->startOfDay();
        $endOfDay = \Carbon\Carbon::parse($date)->endOfDay();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'date'           => $date,
                'new_users'      => User::whereBetween('created_at', [$startOfDay, $endOfDay])->count(),
                'total_deposit'  => Deposit::where('status', 'approved')->whereBetween('approved_at', [$startOfDay, $endOfDay])->sum('amount'),
                'total_withdraw' => Withdrawal::where('status', 'approved')->whereBetween('approved_at', [$startOfDay, $endOfDay])->sum('amount'),
                'total_bet'      => Transaction::where('type', 'bet')->whereBetween('created_at', [$startOfDay, $endOfDay])->sum('amount'),
                'total_win'      => Transaction::where('type', 'win')->whereBetween('created_at', [$startOfDay, $endOfDay])->sum('amount'),
                'total_bonus'    => Transaction::where('type', 'bonus')->whereBetween('created_at', [$startOfDay, $endOfDay])->sum('amount'),
                'deposit_count'  => Deposit::where('status', 'approved')->whereBetween('approved_at', [$startOfDay, $endOfDay])->count(),
                'withdraw_count' => Withdrawal::where('status', 'approved')->whereBetween('approved_at', [$startOfDay, $endOfDay])->count(),
            ],
        ]);
    }

    public function monthly(Request $request): JsonResponse
    {
        $month = $request->input('month', now()->format('Y-m'));
        $startOfMonth = \Carbon\Carbon::parse($month . '-01')->startOfMonth();
        $endOfMonth = \Carbon\Carbon::parse($month . '-01')->endOfMonth();

        $dailyData = DB::table('transactions')
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(CASE WHEN type = "bet" THEN amount ELSE 0 END) as total_bet'),
                DB::raw('SUM(CASE WHEN type = "win" THEN amount ELSE 0 END) as total_win'),
                DB::raw('SUM(CASE WHEN type = "deposit" THEN amount ELSE 0 END) as total_deposit'),
                DB::raw('SUM(CASE WHEN type = "withdraw" THEN amount ELSE 0 END) as total_withdraw')
            )
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->where('status', 'completed')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'month'      => $month,
                'daily_data' => $dailyData,
                'summary'    => [
                    'total_deposit'  => $dailyData->sum('total_deposit'),
                    'total_withdraw' => $dailyData->sum('total_withdraw'),
                    'total_bet'      => $dailyData->sum('total_bet'),
                    'total_win'      => $dailyData->sum('total_win'),
                ],
            ],
        ]);
    }

    public function profitLoss(Request $request): JsonResponse
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $start = \Carbon\Carbon::parse($from)->startOfDay();
        $end = \Carbon\Carbon::parse($to)->endOfDay();

        $totalDeposit  = Deposit::where('status', 'approved')->whereBetween('approved_at', [$start, $end])->sum('amount');
        $totalWithdraw = Withdrawal::where('status', 'approved')->whereBetween('approved_at', [$start, $end])->sum('amount');
        $totalBet      = Transaction::where('type', 'bet')->whereBetween('created_at', [$start, $end])->sum('amount');
        $totalWin      = Transaction::where('type', 'win')->whereBetween('created_at', [$start, $end])->sum('amount');
        $totalBonus    = Transaction::where('type', 'bonus')->whereBetween('created_at', [$start, $end])->sum('amount');

        $profit = bcsub(bcsub($totalBet, $totalWin, 2), $totalBonus, 2);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'from'            => $from,
                'to'              => $to,
                'total_deposit'   => (float) $totalDeposit,
                'total_withdraw'  => (float) $totalWithdraw,
                'total_bet'       => (float) $totalBet,
                'total_win'       => (float) $totalWin,
                'total_bonus'     => (float) $totalBonus,
                'profit'          => (float) $profit,
            ],
        ]);
    }
}