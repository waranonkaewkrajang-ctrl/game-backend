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
            $startDate = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : now()->startOfDay();
            $endDate = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : now()->endOfDay();
            $thisMonth = now()->startOfMonth();

            $chartStartDate = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : now()->subDays(6)->startOfDay();
            $chartEndDate = $endDate->copy();

            // 2. ดึงข้อมูล
            $deposits = Deposit::where('status', 'approved')->whereBetween('approved_at', [$chartStartDate, $chartEndDate])->get();
            $withdrawals = Withdrawal::where('status', 'approved')->whereBetween('approved_at', [$chartStartDate, $chartEndDate])->get();
            $transactions = Transaction::whereIn('type', ['bet', 'win'])->whereBetween('created_at', [$chartStartDate, $chartEndDate])->get();

            // 3. จัดกลุ่มข้อมูลแบบปลอดภัย ป้องกัน Error format() on null/string
            $chartData = [];
            for ($date = $chartStartDate->copy(); $date->lte($chartEndDate); $date->addDay()) {
                $dateString = $date->format('Y-m-d');
                
                $chartData[] = [
                    'name' => $date->format('d/m'),
                    'deposit' => (float) $deposits->filter(function($d) use ($dateString) {
                        return $d->approved_at && Carbon::parse($d->approved_at)->format('Y-m-d') === $dateString;
                    })->sum('amount'),
                    
                    'withdraw' => (float) $withdrawals->filter(function($w) use ($dateString) {
                        return $w->approved_at && Carbon::parse($w->approved_at)->format('Y-m-d') === $dateString;
                    })->sum('amount'),
                    
                    'bet' => (float) $transactions->filter(function($t) use ($dateString) {
                        return $t->created_at && Carbon::parse($t->created_at)->format('Y-m-d') === $dateString && $t->type === 'bet';
                    })->sum('amount'),
                    
                    'win' => (float) $transactions->filter(function($t) use ($dateString) {
                        return $t->created_at && Carbon::parse($t->created_at)->format('Y-m-d') === $dateString && $t->type === 'win';
                    })->sum('amount'),
                ];
            }

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'today' => [
                        'new_users'       => User::whereBetween('created_at', [$startDate, $endDate])->count(),
                        'total_deposit'   => Deposit::where('status', 'approved')->whereBetween('approved_at', [$startDate, $endDate])->sum('amount'),
                        'total_withdraw'  => Withdrawal::where('status', 'approved')->whereBetween('approved_at', [$startDate, $endDate])->sum('amount'),
                        'total_bet'       => Transaction::where('type', 'bet')->whereBetween('created_at', [$startDate, $endDate])->sum('amount'),
                        'total_win'       => Transaction::where('type', 'win')->whereBetween('created_at', [$startDate, $endDate])->sum('amount'),
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
                        'active_users'    => User::where('status', 'active')->count(),
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