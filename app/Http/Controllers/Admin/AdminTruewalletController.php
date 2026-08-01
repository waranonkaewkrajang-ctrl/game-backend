<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TruewalletTransaction;
use App\Models\Setting;
use Illuminate\Http\Request;

class AdminTruewalletController extends Controller
{
    /**
     * รายการเดินบัญชี TrueWallet ทั้งหมด (กล่องเก็บเงิน)
     * GET /api/admin/truewallet/transactions
     */
    public function index(Request $request)
    {
        $query = TruewalletTransaction::with('user', 'deposit')
            ->orderBy('created_at', 'desc');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($phone = $request->query('phone')) {
            $query->where('sender_mobile', 'LIKE', "%{$phone}%");
        }

        if ($date = $request->query('date')) {
            $query->whereDate('created_at', $date);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        return response()->json($query->paginate($request->query('per_page', 20)));
    }

    /**
     * สรุปยอด
     * GET /api/admin/truewallet/summary
     */
    public function summary()
    {
        $today = now()->startOfDay();

        return response()->json([
            'today' => [
                'total_amount'    => TruewalletTransaction::where('created_at', '>=', $today)->sum('amount'),
                'total_count'     => TruewalletTransaction::where('created_at', '>=', $today)->count(),
                'matched_count'   => TruewalletTransaction::where('created_at', '>=', $today)->where('status', 'matched')->count(),
                'unmatched_count' => TruewalletTransaction::where('created_at', '>=', $today)->where('status', 'unmatched')->count(),
            ],
            'all' => [
                'total_amount'    => TruewalletTransaction::sum('amount'),
                'total_count'     => TruewalletTransaction::count(),
                'matched_count'   => TruewalletTransaction::where('status', 'matched')->count(),
                'unmatched_count' => TruewalletTransaction::where('status', 'unmatched')->count(),
            ],
            'accounts' => json_decode(
                Setting::where('key', 'truewallet_accounts')->value('value') ?? '[]', true
            ),
        ]);
    }

    /**
     * ดูรายการที่ยังไม่แมท (เงินเข้าแต่ไม่รู้ว่าของใคร)
     * GET /api/admin/truewallet/unmatched
     */
    public function unmatched()
    {
        $transactions = TruewalletTransaction::where('status', 'unmatched')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($transactions);
    }

    /**
     * Admin จับคู่เอง (กรณีลูกค้าโอนเบอร์อื่น)
     * POST /api/admin/truewallet/{id}/match
     */
    public function match(Request $request, int $id)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $twTx = TruewalletTransaction::findOrFail($id);

        if ($twTx->status === 'matched' && $twTx->deposit_id) {
            return response()->json(['message' => 'รายการนี้แมทไปแล้ว'], 422);
        }

        $twTx->update([
            'status'  => 'matched',
            'user_id' => $request->input('user_id'),
        ]);

        return response()->json([
            'message' => 'จับคู่สำเร็จ',
            'data'    => $twTx->load('user'),
        ]);
    }

    /**
     * ข้ามรายการ
     * POST /api/admin/truewallet/{id}/ignore
     */
    public function ignore(int $id)
    {
        $twTx = TruewalletTransaction::findOrFail($id);
        $twTx->update(['status' => 'ignored']);

        return response()->json(['message' => 'ข้ามรายการแล้ว']);
    }
}
