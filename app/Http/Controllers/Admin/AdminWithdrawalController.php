<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Models\User;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWithdrawalController extends Controller
{
    public function __construct(
        private WithdrawalService $withdrawalService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // ปลดล็อคที่หมดอายุ (เกิน 5 นาที)
        Withdrawal::where('processing_by', '!=', null)
            ->where('processing_at', '<', now()->subMinutes(5))
            ->update(['processing_by' => null, 'processing_at' => null]);

        $withdrawals = Withdrawal::with(['user', 'approver'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, function ($q, $search) {
                $q->whereHas('user', function ($q) use ($search) {
                    $q->where('username', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data'   => $withdrawals,
        ]);
    }

    // Admin กดล็อครายการ (กันชน)
    public function lock(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->status !== 'pending') {
            return response()->json(['message' => 'รายการนี้ดำเนินการแล้ว'], 422);
        }

        if ($withdrawal->processing_by && $withdrawal->processing_by !== $request->user()->id
            && $withdrawal->processing_at > now()->subMinutes(5)) {
            $admin = User::find($withdrawal->processing_by);
            return response()->json([
                'message' => "กำลังดำเนินการโดย {$admin->username}",
                'locked_by' => $admin->username,
            ], 423);
        }

        $withdrawal->update([
            'processing_by' => $request->user()->id,
            'processing_at' => now(),
        ]);

        return response()->json(['status' => 'locked', 'message' => 'ล็อครายการสำเร็จ']);
    }

    // ปลดล็อค
    public function unlock(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->processing_by === $request->user()->id) {
            $withdrawal->update(['processing_by' => null, 'processing_at' => null]);
        }
        return response()->json(['status' => 'unlocked']);
    }

    public function approve(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        // เช็คล็อค
        if ($withdrawal->processing_by && $withdrawal->processing_by !== $request->user()->id
            && $withdrawal->processing_at > now()->subMinutes(5)) {
            $admin = User::find($withdrawal->processing_by);
            return response()->json(['message' => "กำลังดำเนินการโดย {$admin->username}"], 423);
        }

        try {
            $withdrawal = $this->withdrawalService->approve($withdrawal, $request->user()->id);
            $withdrawal->update(['processing_by' => null, 'processing_at' => null]);
            app(\App\Services\TelegramService::class)->notifyWithdraw($withdrawal->user->username, $withdrawal->amount);
            return response()->json([
                'status'  => 'success',
                'message' => 'อนุมัติถอนเงินสำเร็จ',
                'data'    => $withdrawal->load('user', 'approver'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function reject(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        if ($withdrawal->processing_by && $withdrawal->processing_by !== $request->user()->id
            && $withdrawal->processing_at > now()->subMinutes(5)) {
            $admin = User::find($withdrawal->processing_by);
            return response()->json(['message' => "กำลังดำเนินการโดย {$admin->username}"], 423);
        }

        try {
            $withdrawal = $this->withdrawalService->reject($withdrawal, $request->user()->id, $data['reason']);
            $withdrawal->update(['processing_by' => null, 'processing_at' => null]);
            return response()->json([
                'status'  => 'success',
                'message' => 'คืนเครดิตสำเร็จ',
                'data'    => $withdrawal->load('user', 'approver'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}