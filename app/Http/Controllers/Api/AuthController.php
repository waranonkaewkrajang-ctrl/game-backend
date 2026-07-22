<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    public function __construct(
        private WalletService $walletService,
    ) {}

    /**
     * สมัครสมาชิก
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username'      => 'required|string|min:4|max:50|unique:users',
            'phone'         => 'required|string|min:10|max:20|unique:users',
            'password'      => 'required|string|min:6|confirmed',
            'full_name'     => 'nullable|string|max:100',
            'bank_code'     => 'required|string|max:10',
            'bank_account'  => 'required|string|max:20',
            'bank_name'     => 'required|string|max:100',
            'referral_code' => 'nullable|string|max:20',
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
            'referral_code' => strtoupper(Str::random(8)),
            'referred_by'   => $referredBy,
            'last_login_ip' => $request->ip(),
            'last_login_at' => now(),
        ]);

        $this->walletService->createWallet($user);

        app(\App\Services\TelegramService::class)->notifyRegister($user->username, $user->phone);


        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'status'  => 'success',
            'message' => 'สมัครสมาชิกสำเร็จ',
            'data'    => [
                'user'  => $user->load('wallet'),
                'token' => $token,
            ],
        ], 201);
    }

    /**
     * เข้าสู่ระบบ — ขั้นที่ 1 (ใส่ username + password)
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $data['username'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'],
            ]);
        }

        if (!$user->isActive()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'บัญชีถูกระงับ',
            ], 403);
        }

        // ถ้าเปิด 2FA → ต้องยืนยัน OTP ก่อน
        if ($user->two_factor_enabled) {
            return response()->json([
                'status'       => 'two_factor_required',
                'message'      => 'กรุณากรอกรหัส 2FA',
                'two_factor_token' => encrypt($user->id . '|' . now()->addMinutes(5)->timestamp),
            ]);
        }

        return $this->issueToken($user, $request);
    }

    /**
     * เข้าสู่ระบบ — ขั้นที่ 2 (ยืนยัน 2FA OTP)
     */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'two_factor_token' => 'required|string',
            'otp'              => 'required|string|size:6',
        ]);

        try {
            $decrypted = decrypt($data['two_factor_token']);
            [$userId, $expiresAt] = explode('|', $decrypted);

            if (now()->timestamp > (int) $expiresAt) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'รหัสหมดอายุ กรุณา login ใหม่',
                ], 401);
            }

            $user = User::findOrFail($userId);

            $google2fa = new Google2FA();
            $valid = $google2fa->verifyKey($user->two_factor_secret, $data['otp']);

            if (!$valid) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'รหัส 2FA ไม่ถูกต้อง',
                ], 401);
            }

            return $this->issueToken($user, $request);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Token ไม่ถูกต้อง',
            ], 401);
        }
    }

    /**
     * เปิดใช้งาน 2FA — สร้าง secret key
     */
    public function enableTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();
        $google2fa = new Google2FA();

        $secret = $google2fa->generateSecretKey();

        $user->update(['two_factor_secret' => $secret]);

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->username,
            $secret
        );

        return response()->json([
            'status' => 'success',
            'data'   => [
                'secret'     => $secret,
                'qr_code_url' => $qrCodeUrl,
            ],
        ]);
    }

    /**
     * ยืนยันเปิด 2FA — ต้องกรอก OTP ยืนยันก่อนเปิดจริง
     */
    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        $user = $request->user();
        $google2fa = new Google2FA();

        $valid = $google2fa->verifyKey($user->two_factor_secret, $data['otp']);

        if (!$valid) {
            return response()->json([
                'status'  => 'error',
                'message' => 'รหัส OTP ไม่ถูกต้อง',
            ], 400);
        }

        $user->update(['two_factor_enabled' => true]);

        return response()->json([
            'status'  => 'success',
            'message' => 'เปิดใช้งาน 2FA สำเร็จ',
        ]);
    }

    /**
     * ปิด 2FA
     */
    public function disableTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        $user = $request->user();
        $google2fa = new Google2FA();

        $valid = $google2fa->verifyKey($user->two_factor_secret, $data['otp']);

        if (!$valid) {
            return response()->json([
                'status'  => 'error',
                'message' => 'รหัส OTP ไม่ถูกต้อง',
            ], 400);
        }

        $user->update([
            'two_factor_secret'  => null,
            'two_factor_enabled' => false,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'ปิด 2FA สำเร็จ',
        ]);
    }

    /**
     * ออกจากระบบ
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'ออกจากระบบสำเร็จ',
        ]);
    }

    /**
     * ดูข้อมูลตัวเอง
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => $request->user()->load('wallet'),
        ]);
    }

    /**
     * ออก token และอัพเดท login info
     */
    private function issueToken(User $user, Request $request): JsonResponse
    {
        $user->update([
            'last_login_ip' => $request->ip(),
            'last_login_at' => now(),
        ]);

        $user->tokens()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'data'   => [
                'user'  => $user->load('wallet'),
                'token' => $token,
            ],
        ]);
    }
}