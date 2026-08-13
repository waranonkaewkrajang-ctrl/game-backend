<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminMarketingController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $minDaysInactive = (int) $request->input('min_days_inactive', 0);
        $minDeposit      = (float) $request->input('min_deposit', 0);
        $sortBy          = $request->input('sort_by', 'days_inactive');
        $sortOrder       = $request->input('sort_order', 'desc');
        $perPage         = (int) $request->input('per_page', 50);
        $search          = $request->input('search');

        $query = User::query()
            ->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
            ->select(
                'users.id',
                'users.username',
                'users.amb_username',
                'users.phone',
                'users.full_name',
                'users.bank_name',
                'users.bank_code',
                'users.bank_account',
                'users.status',
                'users.last_login_at',
                'users.created_at',
                DB::raw('COALESCE(wallets.balance, 0) as balance'),
                DB::raw('COALESCE(wallets.total_deposit, 0) as total_deposit'),
                DB::raw('COALESCE(wallets.total_withdraw, 0) as total_withdraw'),
                DB::raw('COALESCE(wallets.total_deposit, 0) - COALESCE(wallets.total_withdraw, 0) as profit'),
                DB::raw('CASE WHEN users.last_login_at IS NULL THEN 9999 ELSE DATEDIFF(NOW(), users.last_login_at) END as days_inactive')
            );

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('users.username', 'like', "%{$search}%")
                    ->orWhere('users.phone', 'like', "%{$search}%")
                    ->orWhere('users.full_name', 'like', "%{$search}%")
                    ->orWhere('users.bank_name', 'like', "%{$search}%");
            });
        }

        if ($minDaysInactive > 0) {
            $query->havingRaw('days_inactive >= ?', [$minDaysInactive]);
        }

        if ($minDeposit > 0) {
            $query->havingRaw('total_deposit >= ?', [$minDeposit]);
        }

        $allowedSort = ['days_inactive', 'total_deposit', 'total_withdraw', 'profit', 'balance', 'last_login_at', 'created_at'];
        if (!in_array($sortBy, $allowedSort)) $sortBy = 'days_inactive';
        if (!in_array($sortOrder, ['asc', 'desc'])) $sortOrder = 'desc';

        $query->orderBy($sortBy, $sortOrder);

        $paginated = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $paginated,
        ]);
    }

    public function export(Request $request)
    {
        $minDaysInactive = (int) $request->input('min_days_inactive', 0);
        $minDeposit      = (float) $request->input('min_deposit', 0);

        $query = User::query()
            ->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
            ->select(
                'users.username',
                'users.phone',
                'users.full_name',
                'users.bank_name',
                'users.bank_code',
                'users.bank_account',
                'users.status',
                'users.last_login_at',
                'users.created_at',
                DB::raw('COALESCE(wallets.total_deposit, 0) as total_deposit'),
                DB::raw('COALESCE(wallets.total_withdraw, 0) as total_withdraw'),
                DB::raw('COALESCE(wallets.total_deposit, 0) - COALESCE(wallets.total_withdraw, 0) as profit'),
                DB::raw('CASE WHEN users.last_login_at IS NULL THEN 9999 ELSE DATEDIFF(NOW(), users.last_login_at) END as days_inactive')
            );

        if ($minDaysInactive > 0) $query->havingRaw('days_inactive >= ?', [$minDaysInactive]);
        if ($minDeposit > 0)       $query->havingRaw('total_deposit >= ?', [$minDeposit]);

        $users = $query->orderBy('days_inactive', 'desc')->get();

        $filename = 'marketing_users_' . now()->format('Y-m-d_His') . '.csv';

        $callback = function () use ($users) {
            $fh = fopen('php://output', 'w');
            fwrite($fh, "\xEF\xBB\xBF"); // BOM for Thai in Excel
            fputcsv($fh, ['Username', 'ชื่อ', 'เบอร์โทร', 'ธนาคาร', 'เลขบัญชี', 'ยอดฝากรวม', 'ยอดถอนรวม', 'กำไร/ขาดทุน', 'หายไป (วัน)', 'เข้าล่าสุด', 'สมัครเมื่อ', 'สถานะ']);
            foreach ($users as $u) {
                fputcsv($fh, [
                    $u->username,
                    $u->full_name ?? $u->bank_name ?? '-',
                    $u->phone,
                    $u->bank_code ?? '-',
                    $u->bank_account ?? '-',
                    number_format($u->total_deposit, 2),
                    number_format($u->total_withdraw, 2),
                    number_format($u->profit, 2),
                    $u->days_inactive >= 9999 ? 'ไม่เคยเข้า' : $u->days_inactive,
                    $u->last_login_at ? $u->last_login_at->setTimezone('Asia/Bangkok')->format('Y-m-d H:i') : '-',
                    $u->created_at->setTimezone('Asia/Bangkok')->format('Y-m-d H:i'),
                    $u->status,
                ]);
            }
            fclose($fh);
        };

        return response()->stream($callback, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}