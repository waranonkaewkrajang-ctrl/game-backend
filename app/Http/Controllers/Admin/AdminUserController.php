<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    // =========================================================
    // ส่วนการจัดการลูกค้า (Users)
    // =========================================================

    public function index(Request $request): JsonResponse
    {
        $users = User::with('wallet')
            ->when($request->search, function ($q, $search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%");
            })
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data'   => $users,
        ]);
    }

    // =========================================================
    // 🆕 สมัครสมาชิกให้ลูกค้า (Admin ตั้งข้อมูลให้)
    // =========================================================
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username'      => 'required|string|min:4|max:50|unique:users',
            'phone'         => 'required|string|min:10|max:20|unique:users',
            'password'      => 'required|string|min:6|max:50',
            'full_name'     => 'nullable|string|max:100',
            'bank_code'     => 'required|string|max:10',
            'bank_account'  => 'required|string|max:20|unique:users,bank_account',
            'bank_name'     => 'required|string|max:100',
            'referral_code' => 'nullable|string|max:20',
        ], [
            'username.unique'       => 'ชื่อผู้ใช้นี้ถูกใช้แล้ว',
            'username.required'     => 'กรุณากรอกชื่อผู้ใช้',
            'username.min'          => 'ชื่อผู้ใช้ต้องมีอย่างน้อย 4 ตัว',
            'phone.unique'          => 'เบอร์โทรนี้ถูกใช้แล้ว',
            'phone.required'        => 'กรุณากรอกเบอร์โทร',
            'phone.min'             => 'เบอร์โทรไม่ถูกต้อง',
            'password.required'     => 'กรุณาตั้งรหัสผ่าน',
            'password.min'          => 'รหัสผ่านอย่างน้อย 6 ตัว',
            'bank_code.required'    => 'กรุณาเลือกธนาคาร',
            'bank_account.unique'   => 'เลขบัญชีนี้ถูกใช้แล้ว',
            'bank_account.required' => 'กรุณากรอกเลขบัญชี',
            'bank_name.required'    => 'กรุณากรอกชื่อบัญชี',
        ]);

        $referredBy = null;
        if (!empty($data['referral_code'])) {
            $referrer = User::where('referral_code', $data['referral_code'])->first();
            $referredBy = $referrer?->id;
        }

        $user = User::create([
            'username'      => $data['username'],
            'phone'         => $data['phone'],
            'password'      => Hash::make($data['password']),
            'full_name'     => $data['full_name'] ?? null,
            'bank_code'     => $data['bank_code'],
            'bank_account'  => $data['bank_account'],
            'bank_name'     => $data['bank_name'],
            'referral_code' => strtoupper(\Illuminate\Support\Str::random(8)),
            'referred_by'   => $referredBy,
            'status'        => 'active',
        ]);

        $this->walletService->createWallet($user);

        // Audit log: แอดมินคนไหนสมัครให้
        $adminId = $request->user()?->id ?? 'unknown';
        \Log::info("Admin #{$adminId} created user #{$user->id} ({$user->username})");

        return response()->json([
            'status'  => 'success',
            'message' => 'สมัครสมาชิกให้ลูกค้าสำเร็จ',
            'data'    => $user->load('wallet'),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => $user->load(['wallet', 'deposits.approvedBy', 'withdrawals.approver']),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
    'status'       => 'nullable|in:active,suspended,banned',
    'full_name'    => 'nullable|string|max:100',
    'phone'        => 'nullable|string|max:20',
    'bank_code'    => 'nullable|string|max:20',
    'bank_account' => 'nullable|string|max:30',
    'bank_name'    => 'nullable|string|max:100',
]);

        $user->update(array_filter($data));

        return response()->json([
            'status'  => 'success',
            'message' => 'อัพเดทข้อมูลสำเร็จ',
            'data'    => $user->fresh(),
        ]);
    }

    public function adjustBalance(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'amount'      => 'required|numeric|not_in:0',
            'description' => 'required|string|max:255',
        ]);

        try {
            $transaction = $this->walletService->adjust(
                $user,
                $data['amount'],
                $data['description'],
                $request->user()->id
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'ปรับยอดสำเร็จ',
                'data'    => $transaction,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function adjustTickets(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'amount'      => 'required|integer|not_in:0',
            'description' => 'nullable|string|max:255',
        ]);

        $wallet = $user->wallet;
        if (!$wallet) {
            return response()->json(['status' => 'error', 'message' => 'ไม่พบ wallet'], 404);
        }

        $before = $wallet->ticket_balance;
        if ($data['amount'] > 0) {
            $wallet->increment('ticket_balance', $data['amount']);
        } else {
            if ($before + $data['amount'] < 0) {
                return response()->json(['status' => 'error', 'message' => 'ตั๋วไม่พอหัก'], 400);
            }
            $wallet->decrement('ticket_balance', abs($data['amount']));
        }

        \DB::table('transactions')->insert([
            'user_id' => $user->id,
            'reference_id' => 'TKT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -8)),
            'type' => 'ticket_adjust',
            'direction' => $data['amount'] > 0 ? 'in' : 'out',
            'amount' => abs($data['amount']),
            'balance_before' => $before,
            'balance_after' => $wallet->fresh()->ticket_balance,
            'description' => $data['description'] ?? 'ปรับตั๋ววงล้อ',
            'meta' => json_encode(['adjusted_by' => $request->user()->id]),
            'status' => 'completed',
            'processed_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => "ปรับตั๋วสำเร็จ ({$data['amount']} ใบ)",
            'data'    => ['ticket_balance' => $wallet->fresh()->ticket_balance],
        ]);
    }

    public function adjustPoints(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'amount'      => 'required|integer|not_in:0',
            'description' => 'nullable|string|max:255',
        ]);

        $wallet = $user->wallet;
        if (!$wallet) {
            return response()->json(['status' => 'error', 'message' => 'ไม่พบ wallet'], 404);
        }

        $before = $wallet->point_balance;
        if ($data['amount'] > 0) {
            $wallet->increment('point_balance', $data['amount']);
        } else {
            if ($before + $data['amount'] < 0) {
                return response()->json(['status' => 'error', 'message' => 'คะแนนไม่พอหัก'], 400);
            }
            $wallet->decrement('point_balance', abs($data['amount']));
        }

        \DB::table('transactions')->insert([
            'user_id' => $user->id,
            'reference_id' => 'PNT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -8)),
            'type' => 'point_adjust',
            'direction' => $data['amount'] > 0 ? 'in' : 'out',
            'amount' => abs($data['amount']),
            'balance_before' => $before,
            'balance_after' => $wallet->fresh()->point_balance,
            'description' => $data['description'] ?? 'ปรับคะแนน',
            'meta' => json_encode(['adjusted_by' => $request->user()->id]),
            'status' => 'completed',
            'processed_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => "ปรับคะแนนสำเร็จ ({$data['amount']} คะแนน)",
            'data'    => ['point_balance' => $wallet->fresh()->point_balance],
        ]);
    }

    // =========================================================
    // แทรกเพิ่มตรงนี้: ส่วนของการจัดการสิทธิ์และพนักงาน (Admins)
    // =========================================================

    public function getAdmins()
    {
        $admins = Admin::all()->map(function($admin) {
            return [
                'id' => $admin->id,
                'username' => $admin->username,
                'name' => $admin->name,
                'role' => $admin->role,
                'permissions' => json_decode($admin->permissions ?? '[]', true),
            ];
        });
        
        return response()->json($admins);
    }

    public function storeAdmin(Request $request)
    {
        $request->validate([
            'username' => 'required|unique:admins,username',
            'password' => 'required|min:6',
            'name' => 'required|string',
            'role' => 'required|in:super_admin,admin,staff',
            'permissions' => 'nullable|array'
        ]);

        $admin = new Admin();
        $admin->username = $request->username;
        $admin->password = Hash::make($request->password);
        $admin->name = $request->name;
        $admin->role = $request->role;
        $admin->permissions = json_encode($request->permissions ?? []); 
        $admin->save();

        return response()->json(['message' => 'สร้างบัญชีผู้ดูแลระบบสำเร็จ']);
    }

    public function updateAdmin(Request $request, $id)
    {
        $admin = Admin::findOrFail($id);

        $request->validate([
            'username' => 'required|unique:admins,username,' . $id,
            'name' => 'required|string',
            'role' => 'required|in:super_admin,admin,staff',
            'permissions' => 'nullable|array'
        ]);

        $admin->username = $request->username;
        $admin->name = $request->name;
        $admin->role = $request->role;
        $admin->permissions = json_encode($request->permissions ?? []);

        if ($request->filled('password')) {
            $admin->password = Hash::make($request->password);
        }

        $admin->save();

        return response()->json(['message' => 'อัปเดตข้อมูลผู้ดูแลระบบสำเร็จ']);
    }
}