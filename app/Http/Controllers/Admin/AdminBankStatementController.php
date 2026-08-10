<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankStatement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBankStatementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BankStatement::with(['user:id,username', 'admin:id,username'])
            ->orderBy('created_at', 'desc');

        // Filters
        if ($request->filled('bank_code')) {
            $query->where('bank_code', $request->query('bank_code'));
        }
        if ($request->filled('method')) {
            $query->where('approved_method', $request->query('method'));
        }
        if ($request->filled('search')) {
            $q = $request->query('search');
            $query->where(function ($qb) use ($q) {
                $qb->where('reference_id', 'like', "%{$q}%")
                    ->orWhere('from_name', 'like', "%{$q}%")
                    ->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$q}%"));
            });
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to'));
        }

        $perPage = min((int) $request->query('per_page', 30), 100);
        $data = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $data,
        ]);
    }

    public function summary(): JsonResponse
    {
        $today = now('Asia/Bangkok')->startOfDay()->utc();
        $endToday = now('Asia/Bangkok')->endOfDay()->utc();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'today_count' => BankStatement::whereBetween('created_at', [$today, $endToday])->count(),
                'today_sum'   => (float) BankStatement::whereBetween('created_at', [$today, $endToday])->sum('amount'),
                'total_count' => BankStatement::count(),
            ],
        ]);
    }
}