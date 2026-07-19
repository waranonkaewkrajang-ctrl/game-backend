<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $admin = Admin::where('username', $data['username'])->first();

        if (!$admin || !Hash::check($data['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'username' => ['ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'],
            ]);
        }

        if (!$admin->is_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'บัญชีถูกระงับ',
            ], 403);
        }

        // --- เริ่ม Logic ใหม่: บังคับ 2FA หน้า Login ---

        // 1. ถ้ายังไม่มี Secret (แปลว่าเพิ่ง Login ครั้งแรก) ให้สร้าง QR Code
        if (empty($admin->two_factor_secret)) {
            $google2fa = new Google2FA();
            $secret = $google2fa->generateSecretKey();

            // บันทึก Secret ลงฐานข้อมูล
            $admin->update(['two_factor_secret' => $secret]);

            $qrCodeUrl = $google2fa->getQRCodeUrl(
                config('app.name') . ' Admin',
                $admin->username,
                $secret
            );

            return response()->json([
                'status'           => 'two_factor_setup_required',
                'message'          => 'กรุณาสแกน QR Code เพื่อตั้งค่า 2FA',
                'qr_code_url'      => $qrCodeUrl,
                'two_factor_token' => encrypt($admin->id . '|' . now()->addMinutes(15)->timestamp),
            ]);
        }

        // 2. ถ้ามี Secret แล้ว (เคยสแกนแล้ว) ให้บังคับกรอก OTP
        if (!empty($admin->two_factor_secret)) {
            return response()->json([
                'status'           => 'two_factor_required',
                'message'          => 'กรุณากรอกรหัส 2FA',
                'two_factor_token' => encrypt($admin->id . '|' . now()->addMinutes(5)->timestamp),
            ]);
        }

        return $this->issueToken($admin, $request);
    }

    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'two_factor_token' => 'required|string',
            'otp'              => 'required|string|size:6',
        ]);

        try {
            $decrypted = decrypt($data['two_factor_token']);
            [$adminId, $expiresAt] = explode('|', $decrypted);

            if (now()->timestamp > (int) $expiresAt) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'รหัสหมดอายุ กรุณา login ใหม่',
                ], 401);
            }

            $admin = Admin::findOrFail($adminId);
            $google2fa = new Google2FA();
            $valid = $google2fa->verifyKey($admin->two_factor_secret, $data['otp']);

            if (!$valid) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'รหัส 2FA ไม่ถูกต้อง',
                ], 401);
            }

            // --- เมื่อกรอก OTP ถูกต้อง ให้อัปเดตว่าตั้งค่า 2FA สำเร็จแล้ว ---
            if (!$admin->two_factor_enabled) {
                $admin->update(['two_factor_enabled' => true]);
            }

            return $this->issueToken($admin, $request);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Token ไม่ถูกต้อง',
            ], 401);
        }
    }

    // ฟังก์ชันนี้เก็บไว้เผื่ออนาคตอยากทำปุ่ม "รีเซ็ตรหัส 2FA" ในหน้าตั้งค่า
    public function enableTwoFactor(Request $request): JsonResponse
    {
        $admin = $request->user();
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $admin->update(['two_factor_secret' => $secret]);

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name') . ' Admin',
            $admin->username,
            $secret
        );

        return response()->json([
            'status' => 'success',
            'data'   => [
                'secret'      => $secret,
                'qr_code_url' => $qrCodeUrl,
            ],
        ]);
    }

    // ฟังก์ชันนี้เก็บไว้ใช้คู่กับ enableTwoFactor
    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        $admin = $request->user();
        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($admin->two_factor_secret, $data['otp']);

        if (!$valid) {
            return response()->json([
                'status'  => 'error',
                'message' => 'รหัส OTP ไม่ถูกต้อง',
            ], 400);
        }

        $admin->update(['two_factor_enabled' => true]);

        return response()->json([
            'status'  => 'success',
            'message' => 'เปิดใช้งาน 2FA สำเร็จ',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'ออกจากระบบสำเร็จ',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => $request->user(),
        ]);
    }

    private function issueToken(Admin $admin, Request $request): JsonResponse
    {
        $admin->update([
            'last_login_ip' => $request->ip(),
            'last_login_at' => now(),
        ]);

        $admin->tokens()->delete();
        $token = $admin->createToken('admin-token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'data'   => [
                'admin' => $admin,
                'token' => $token,
            ],
        ]);
    }
}