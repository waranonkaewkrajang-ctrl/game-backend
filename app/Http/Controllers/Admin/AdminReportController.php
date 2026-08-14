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
        $date = $request->input('date', now('Asia/Bangkok')->toDateString());
        $startOfDay = \Carbon\Carbon::parse($date, 'Asia/Bangkok')->startOfDay();
        $endOfDay = \Carbon\Carbon::parse($date, 'Asia/Bangkok')->endOfDay();

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
        $from = $request->input('from', now('Asia/Bangkok')->startOfMonth()->toDateString());
        $to = $request->input('to', now('Asia/Bangkok')->toDateString());
        // 🇹🇭 Parse date เป็น Bangkok time แล้วแปลงเป็น UTC (เพราะ DB เก็บ UTC)
        $start = \Carbon\Carbon::parse($from, 'Asia/Bangkok')->startOfDay();
        $end = \Carbon\Carbon::parse($to, 'Asia/Bangkok')->endOfDay();

        $totalDeposit  = Deposit::where('status', 'approved')->whereBetween('approved_at', [$start, $end])->sum('amount');
        $totalWithdraw = Withdrawal::where('status', 'approved')->whereBetween('approved_at', [$start, $end])->sum('amount');
        $totalBet      = Transaction::where('type', 'bet')->whereBetween('created_at', [$start, $end])->sum('amount');
        $totalWin      = Transaction::where('type', 'win')->whereBetween('created_at', [$start, $end])->sum('amount');
        $totalBonus    = Transaction::where('type', 'bonus')->whereBetween('created_at', [$start, $end])->sum('amount');

        $profit = bcsub(bcsub($totalBet, $totalWin, 2), $totalBonus, 2);

        // 🆕 Daily breakdown (SQL CONVERT_TZ — แม่นยำ ไม่ขึ้นกับ PHP timezone)
        $depRows = \DB::table('deposits')
            ->selectRaw("DATE(CONVERT_TZ(approved_at,'+00:00','+07:00')) as d, SUM(amount) as total, COUNT(*) as cnt")
            ->where('status', 'approved')
            ->whereBetween('approved_at', [$start, $end])
            ->groupBy('d')->get()->keyBy('d');

        $wdRows = \DB::table('withdrawals')
            ->selectRaw("DATE(CONVERT_TZ(approved_at,'+00:00','+07:00')) as d, SUM(amount) as total, COUNT(*) as cnt")
            ->where('status', 'approved')
            ->whereBetween('approved_at', [$start, $end])
            ->groupBy('d')->get()->keyBy('d');

        $betRows = \DB::table('transactions')
            ->selectRaw("DATE(CONVERT_TZ(created_at,'+00:00','+07:00')) as d, SUM(amount) as total")
            ->where('type', 'bet')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('d')->pluck('total', 'd');

        $winRows = \DB::table('transactions')
            ->selectRaw("DATE(CONVERT_TZ(created_at,'+00:00','+07:00')) as d, SUM(amount) as total")
            ->where('type', 'win')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('d')->pluck('total', 'd');

        $newUserRows = \DB::table('users')
            ->selectRaw("DATE(CONVERT_TZ(created_at,'+00:00','+07:00')) as d, COUNT(*) as cnt")
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('d')->pluck('cnt', 'd');

        $daily = [];
        $cursor = \Carbon\Carbon::parse($from, 'Asia/Bangkok')->startOfDay();
        $stop   = \Carbon\Carbon::parse($to, 'Asia/Bangkok')->startOfDay();

        while ($cursor->lte($stop)) {
            $k = $cursor->format('Y-m-d');
            $dep = (float) ($depRows[$k]->total ?? 0);
            $wd  = (float) ($wdRows[$k]->total ?? 0);
            $bet = (float) ($betRows[$k] ?? 0);
            $win = (float) ($winRows[$k] ?? 0);

            $daily[] = [
                'date'           => $k,
                'date_label'     => $cursor->format('d/m/Y'),
                'total_deposit'  => $dep,
                'total_withdraw' => $wd,
                'deposit_count'  => (int) ($depRows[$k]->cnt ?? 0),
                'withdraw_count' => (int) ($wdRows[$k]->cnt ?? 0),
                'total_bet'      => $bet,
                'total_win'      => $win,
                'new_users'      => (int) ($newUserRows[$k] ?? 0),
                'net_cash'       => round($dep - $wd, 2),
                'game_profit'    => round($bet - $win, 2),
            ];
            $cursor->addDay();
        }
        // เรียงวันล่าสุดขึ้นก่อน
        $daily = array_reverse($daily);

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
                'daily'           => $daily,
            ],
        ]);
    }
}