<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankIncomingTx;
use App\Models\Setting;
use App\Services\BankIncomingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * เงินเข้าจาก bot อ่าน LINE ธนาคาร — bot ส่ง "สิ่งที่อ่านได้" มา เซิร์ฟเวอร์ตัดสินใจเอง (กันซ้ำ / จับคู่ / อนุมัติ)
 */
class AdminBankIncomingController extends Controller
{
    public function __construct(
        private BankIncomingService $service,
    ) {}

    // POST /admin/bank-incoming  (monitor.py)
    public function ingest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank'           => 'nullable|string|max:50',
            'amount'         => 'required|numeric|min:0.01|max:100000000',
            'tx_time'        => 'nullable|string|max:100',
            'from'           => 'nullable|string|max:255',
            'from_account_4' => 'nullable|string|max:10',
            'source'         => 'nullable|string|in:scb_json,text',
            'mode'           => 'nullable|string|in:live,backlog',
        ]);

        try {
            $result = $this->service->ingest($data, $request->user()?->id);
        } catch (\Throwable $e) {
            report($e);
            // ไม่บันทึกอะไร (rollback ทั้งหมด) → bot ส่งซ้ำได้ภายหลัง
            return response()->json(['status' => 'error', 'message' => 'ประมวลผลเงินเข้าไม่สำเร็จ: ' . $e->getMessage()], 500);
        }

        return response()->json(['status' => 'success', 'data' => $result], $result['result'] === 'duplicate' ? 200 : 201);
    }

    // GET /admin/bank-incoming  (ตรวจย้อนหลัง)
    public function index(Request $request): JsonResponse
    {
        $items = BankIncomingTx::with(['deposit.user', 'unmatchedDeposit'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->date, fn ($q, $d) => $q->whereDate('created_at', $d))
            ->orderByDesc('id')
            ->paginate(min((int) $request->input('per_page', 50), 200));

        return response()->json(['status' => 'success', 'data' => $items]);
    }

    // GET /admin/bank-incoming/status  (สถานะ auto + ค่าตั้งที่ใช้อยู่)
    public function status(): JsonResponse
    {
        $cfg = $this->service->config();

        return response()->json(['status' => 'success', 'data' => [
            'auto_enabled' => $cfg['enabled'],
            'paused'       => $cfg['paused_until'] && now()->lt($cfg['paused_until']),
            'paused_until' => $cfg['paused_until']?->format('Y-m-d H:i:s'),
            'settings'     => $cfg,
            'today'        => BankIncomingTx::whereDate('created_at', today())
                ->selectRaw('status, COUNT(*) as n, SUM(amount) as total, SUM(seen_count - 1) as repeats')
                ->groupBy('status')->get(),
        ]]);
    }

    // POST /admin/bank-incoming/resume  (ปลดเบรกฉุกเฉิน)
    public function resume(): JsonResponse
    {
        Setting::setValue('bank_auto_paused_until', '');

        return response()->json(['status' => 'success', 'message' => 'เปิดอนุมัติอัตโนมัติต่อแล้ว']);
    }
}
