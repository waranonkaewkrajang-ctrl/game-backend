<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminRewardController extends Controller
{
    // รายการรางวัลทั้งหมด + filter
    public function index(Request $request)
    {
        $query = Reward::with('user:id,username,phone')
            ->orderBy('created_at', 'desc');

        if ($request->filled('type'))   $query->where('type', $request->type);
        if ($request->filled('status')) $query->where('status', $request->status);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', fn($q) => $q->where('username', 'like', "%{$search}%"));
        }

        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('created_at', '<=', $request->date_to);

        return response()->json([
            'status' => 'success',
            'data'   => $query->paginate($request->input('per_page', 50)),
        ]);
    }

    // สรุปยอดรวม แยกตาม type + status
    public function summary(Request $request)
    {
        $query = Reward::query();

        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('created_at', '<=', $request->date_to);

        $rows = $query->select('type', 'status',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as total'))
            ->groupBy('type', 'status')
            ->get();

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    // สรุปรายผู้เล่น — ใครได้ยอดเสีย/ค่าแนะนำเท่าไหร่
    public function byUser(Request $request)
    {
        $query = Reward::with('user:id,username,phone')
            ->select('user_id',
                DB::raw("SUM(CASE WHEN type='cashback' THEN amount ELSE 0 END) as cashback_total"),
                DB::raw("SUM(CASE WHEN type='referral' THEN amount ELSE 0 END) as referral_total"),
                DB::raw("SUM(CASE WHEN status='pending' THEN amount ELSE 0 END) as pending_total"),
                DB::raw("SUM(CASE WHEN status='claimed' THEN amount ELSE 0 END) as claimed_total"),
                DB::raw("SUM(CASE WHEN status='expired' THEN amount ELSE 0 END) as expired_total"),
                DB::raw('COUNT(*) as reward_count'))
            ->groupBy('user_id')
            ->orderByDesc('claimed_total');

        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('created_at', '<=', $request->date_to);

        return response()->json([
            'status' => 'success',
            'data'   => $query->paginate($request->input('per_page', 50)),
        ]);
    }

    // รายละเอียดรายตัว (meta มี bet/win/loss/percent/from_username)
    public function show($id)
    {
        $reward = Reward::with('user:id,username,phone')->findOrFail($id);
        return response()->json(['status' => 'success', 'data' => $reward]);
    }
}