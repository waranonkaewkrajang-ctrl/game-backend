<?php

namespace App\Http\Controllers\Admin; // หรือ App\Http\Controllers\Admin เช็คให้ตรงของเดิม

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            // 1. จัดการวันที่อย่างปลอดภัย
            $startDate = $request->filled('from') ? Carbon::parse($request->query('from'), 'Asia/Bangkok')->startOfDay() : Carbon::now('Asia/Bangkok')->startOfDay();
            $endDate = $request->filled('to') ? Carbon::parse($request->query('to'), 'Asia/Bangkok')->endOfDay() : Carbon::now('Asia/Bangkok')->endOfDay();
            $thisMonth = Carbon::now('Asia/Bangkok')->startOfMonth();

            $chartStartDate = $request->filled('from') ? Carbon::parse($request->query('from'), 'Asia/Bangkok')->startOfDay() : Carbon::now('Asia/Bangkok')->subDays(6)->startOfDay();
            $chartEndDate = $endDate->copy();

            // 2. ดึงข้อมูลแบบ SQL Group By (CONVERT_TZ — แม่นยำ ไม่ขึ้นกับ PHP timezone)
            $depositRows = \DB::table('deposits')
                ->selectRaw("DATE(approved_at) as d, SUM(amount) as total")
                ->where('status', 'approved')
                ->whereBetween('approved_at', [$chartStartDate, $chartEndDate])
                ->groupBy('d')->pluck('total', 'd');

            $withdrawRows = \DB::table('withdrawals')
                ->selectRaw("DATE(CONVERT_TZ(approved_at,'+00:00','+07:00')) as d, SUM(amount) as total")
                ->where('status', 'approved')
                ->whereBetween('approved_at', [$chartStartDate, $chartEndDate])
                ->groupBy('d')->pluck('total', 'd');

            $betRows = \DB::table('transactions')
                ->selectRaw("DATE(created_at) as d, SUM(amount) as total")
                ->where('type', 'bet')
                ->whereBetween('created_at', [$chartStartDate, $chartEndDate])
                ->groupBy('d')->pluck('total', 'd');

            $winRows = \DB::table('transactions')
                ->selectRaw("DATE(created_at) as d, SUM(amount) as total")
                ->where('type', 'win')
                ->whereBetween('created_at', [$chartStartDate, $chartEndDate])
                ->groupBy('d')->pluck('total', 'd');

            // 3. สร้าง chart data ตามวัน (Bangkok)
            $chartData = [];
            $cursor = $chartStartDate->copy()->startOfDay();
            $chartEndBkk = $chartEndDate->copy()->endOfDay();

            while ($cursor->lte($chartEndBkk)) {
                $key = $cursor->format('Y-m-d');
                $chartData[] = [
                    'name'     => $cursor->format('d/m'),
                    'deposit'  => (float) ($depositRows[$key]  ?? 0),
                    'withdraw' => (float) ($withdrawRows[$key] ?? 0),
                    'bet'      => (float) ($betRows[$key]      ?? 0),
                    'win'      => (float) ($winRows[$key]      ?? 0),
                ];
                $cursor->addDay();
            }

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'today' => [
                        'new_users'           => User::whereBetween('created_at', [$startDate, $endDate])->count(),
                        'total_deposit'       => Deposit::where('status', 'approved')->whereBetween('approved_at', [$startDate, $endDate])->sum('amount'),
                        'total_withdraw'      => Withdrawal::where('status', 'approved')->whereBetween('approved_at', [$startDate, $endDate])->sum('amount'),
                        'total_bet'           => Transaction::where('type', 'bet')->whereBetween('created_at', [$startDate, $endDate])->sum('amount'),
                        'total_win'           => Transaction::where('type', 'win')->whereBetween('created_at', [$startDate, $endDate])->sum('amount'),
                        // 🆕 นับจำนวนบิล
                        'deposit_count'       => Deposit::where('status', 'approved')->whereBetween('approved_at', [$startDate, $endDate])->count(),
                        'withdraw_count'      => Withdrawal::where('status', 'approved')->whereBetween('approved_at', [$startDate, $endDate])->count(),
                        // 🆕 First deposit — ผู้ใช้ที่ฝากครั้งแรกในวันนี้
                        'first_deposit_count' => Deposit::where('status', 'approved')
                                                    ->whereBetween('approved_at', [$startDate, $endDate])
                                                    ->whereIn('user_id', function ($query) use ($startDate, $endDate) {
                                                        $query->select('user_id')
                                                            ->from('deposits')
                                                            ->where('status', 'approved')
                                                            ->groupBy('user_id')
                                                            ->havingRaw('MIN(approved_at) BETWEEN ? AND ?', [$startDate, $endDate]);
                                                    })
                                                    ->distinct('user_id')
                                                    ->count('user_id'),
                    ],
                    'this_month' => [
                        'new_users'       => User::where('created_at', '>=', $thisMonth)->count(),
                        'total_deposit'   => Deposit::where('status', 'approved')->where('approved_at', '>=', $thisMonth)->sum('amount'),
                        'total_withdraw'  => Withdrawal::where('status', 'approved')->where('approved_at', '>=', $thisMonth)->sum('amount'),
                        'total_bet'       => Transaction::where('type', 'bet')->where('created_at', '>=', $thisMonth)->sum('amount'),
                        'total_win'       => Transaction::where('type', 'win')->where('created_at', '>=', $thisMonth)->sum('amount'),
                    ],
                    'overall' => [
                        'total_users'     => User::count(),
                        'active_users'    => User::whereBetween('last_login_at', [$startDate, $endDate])->count(),
                        'total_balance'   => \App\Models\Wallet::sum('balance'),
                    ],
                    'pending' => [
                        'deposits'    => Deposit::where('status', 'pending')->count(),
                        'withdrawals' => Withdrawal::where('status', 'pending')->count(),
                    ],
                    'chart_data' => $chartData,
                ],
            ]);
            
        } catch (\Exception $e) {
            // ดักจับ Error ส่งกลับไปให้ดูว่าพังที่ไหน จะได้ไม่ต้องเดา
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }
}