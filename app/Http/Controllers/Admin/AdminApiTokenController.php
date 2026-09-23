<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminApiTokenController extends Controller
{
    public function index(): JsonResponse
    {
        $tokens = ApiToken::orderByDesc('id')->get()->map(fn ($t) => [
            'id'           => $t->id,
            'name'         => $t->name,
            'token_prefix' => $t->token_prefix,
            'allowed_ips'  => $t->allowed_ips,
            'is_active'    => $t->is_active,
            'calls_count'  => $t->calls_count,
            'last_used_at' => optional($t->last_used_at)->format('Y-m-d H:i:s'),
            'last_used_ip' => $t->last_used_ip,
            'created_at'   => optional($t->created_at)->format('Y-m-d H:i:s'),
        ]);

        return response()->json(['status' => 'success', 'data' => $tokens]);
    }

    /**
     * สร้าง token ใหม่ — คืนค่าเต็มครั้งเดียวเท่านั้น (ฐานข้อมูลเก็บแบบเข้ารหัส)
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'allowed_ips' => 'nullable|string|max:255',
        ]);

        $plain = Str::random(48);

        $token = ApiToken::create([
            'name'         => $data['name'],
            'token_hash'   => ApiToken::hash($plain),
            'token_prefix' => substr($plain, 0, 8),
            'allowed_ips'  => $this->cleanIps($data['allowed_ips'] ?? null),
            'is_active'    => true,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'สร้าง Token สำเร็จ — คัดลอกเก็บไว้ทันที ระบบจะไม่แสดงอีก',
            'data'    => ['id' => $token->id, 'name' => $token->name, 'token' => $plain],
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $token = ApiToken::findOrFail($id);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:100',
            'allowed_ips' => 'nullable|string|max:255',
            'is_active'   => 'sometimes|boolean',
        ]);

        if (array_key_exists('allowed_ips', $data)) {
            $data['allowed_ips'] = $this->cleanIps($data['allowed_ips']);
        }

        $token->update($data);

        return response()->json(['status' => 'success', 'message' => 'บันทึกสำเร็จ']);
    }

    public function destroy(int $id): JsonResponse
    {
        ApiToken::findOrFail($id)->delete();
        return response()->json(['status' => 'success', 'message' => 'เพิกถอน Token แล้ว']);
    }

    /** ประวัติการเรียก API 100 รายการล่าสุด */
    public function logs(Request $request): JsonResponse
    {
        $logs = ApiRequestLog::with('token:id,name')
            ->when($request->filled('token_id'), fn ($q) => $q->where('api_token_id', $request->token_id))
            ->orderByDesc('id')->limit(100)->get()
            ->map(fn ($l) => [
                'id'          => $l->id,
                'token_name'  => $l->token?->name,
                'endpoint'    => $l->endpoint,
                'ip'          => $l->ip,
                'query_value' => $l->query_value,
                'status_code' => $l->status_code,
                'note'        => $l->note,
                'created_at'  => optional($l->created_at)->format('Y-m-d H:i:s'),
            ]);

        return response()->json(['status' => 'success', 'data' => $logs]);
    }

    /** ตัดช่องว่าง/รายการว่างออกจากลิสต์ IP */
    private function cleanIps(?string $raw): ?string
    {
        $list = array_filter(array_map('trim', explode(',', (string) $raw)));
        return $list ? implode(',', $list) : null;
    }
}