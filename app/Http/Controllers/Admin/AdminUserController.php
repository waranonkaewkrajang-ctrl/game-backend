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

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => $user->load(['wallet', 'deposits', 'withdrawals']),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'status'    => 'nullable|in:active,suspended,banned',
            'full_name' => 'nullable|string|max:100',
            'phone'     => 'nullable|string|max:20',
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