<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    /**
     * ให้ผ่านเฉพาะ token ของแอดมินที่ยังเปิดใช้งาน
     * (User กับ Admin ใช้ Sanctum ร่วมกัน — auth:sanctum อย่างเดียวจึงปล่อย token ลูกค้าผ่าน)
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (!$admin instanceof Admin || !$admin->is_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'ไม่มีสิทธิ์เข้าถึง',
            ], 403);
        }

        return $next($request);
    }
}
