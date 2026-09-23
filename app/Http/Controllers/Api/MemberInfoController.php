<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiRequestLog;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class MemberInfoController extends Controller
{
    private const ENDPOINT   = 'member-info';
    private const RATE_LIMIT = 60;   // เรียกได้กี่ครั้งต่อนาทีต่อ 1 token

    public function __invoke(Request $request): JsonResponse
    {
        $ip    = $request->ip();
        $plain = trim((string) $request->input('token'));
        $query = trim((string) $request->input('username'));

        // ── 1) ตรวจ token ──
        $token = $plain !== ''
            ? ApiToken::where('token_hash', ApiToken::hash($plain))->where('is_active', true)->first()
            : null;

        if (!$token) {
            $this->log(null, $ip, $query, 401, 'token ไม่ถูกต้อง');
            return $this->err('invalid token', 401);
        }

        // ── 2) ตรวจ IP ──
        if (!$token->allowsIp($ip)) {
            $this->log($token->id, $ip, $query, 403, 'IP ไม่ได้รับอนุญาต');
            return $this->err('forbidden ip', 403);
        }

        // ── 3) จำกัดจำนวนครั้ง ──
        $key = 'member-info:' . $token->id;
        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT)) {
            $this->log($token->id, $ip, $query, 429, 'เรียกถี่เกินกำหนด');
            return $this->err('too many requests', 429);
        }
        RateLimiter::hit($key, 60);

        // บันทึกการใช้งานล่าสุดของ token
        $token->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $ip,
            'calls_count'  => $token->calls_count + 1,
        ])->save();

        // ── 4) ค้นหาสมาชิก (เบอร์ก่อน แล้วค่อย username) ──
        if ($query === '') {
            $this->log($token->id, $ip, $query, 404, 'ไม่ได้ส่งเบอร์มา');
            return $this->err('member not found', 404);
        }

        $digits = preg_replace('/\D/', '', $query);
        $user = null;
        if ($digits !== '') {
            $user = User::where('phone', $digits)->first()
                ?: User::whereRaw("REPLACE(REPLACE(phone,'-',''),' ','') = ?", [$digits])->first();
        }
        $user = $user ?: User::where('username', $query)->first();

        if (!$user) {
            $this->log($token->id, $ip, $query, 404, 'ไม่พบสมาชิก');
            return $this->err('member not found', 404);
        }

        // ── 5) รวมยอดฝาก/ถอน (เฉพาะที่อนุมัติแล้ว) ──
        $dep = DB::table('deposits')->where('user_id', $user->id)->where('status', 'approved')
            ->selectRaw('COALESCE(SUM(amount),0) total, COUNT(*) cnt, MAX(created_at) last_at')->first();

        $wit = DB::table('withdrawals')->where('user_id', $user->id)->where('status', 'approved')
            ->selectRaw('COALESCE(SUM(amount),0) total, COUNT(*) cnt')->first();

        $referrer = $user->referred_by
            ? (User::where('id', $user->referred_by)->value('username') ?: null)
            : null;

        $this->log($token->id, $ip, $query, 200, 'สำเร็จ');

        return $this->json([
            'total_deposit'  => (string) round((float) ($dep->total ?? 0), 2),
            'total_withdraw' => (string) round((float) ($wit->total ?? 0), 2),
            'count_deposit'  => (string) ($dep->cnt ?? 0),
            'count_withdraw' => (string) ($wit->cnt ?? 0),
            'account_name'   => $user->full_name ?: ($user->bank_name ?: null),
            'account_number' => $user->bank_account ?: null,
            'bank_short'     => $user->bank_code ? strtoupper($user->bank_code) : null,
            'registered_at'  => optional($user->created_at)->format('Y-m-d'),
            'referrer'       => $referrer,
            'last_deposit'   => $dep->last_at ? date('Y-m-d H:i', strtotime($dep->last_at)) : null,
        ], 200);
    }

    /** ตอบ JSON โดยไม่แปลงภาษาไทยเป็นรหัส */
    private function json(array $data, int $status): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function err(string $message, int $status): JsonResponse
    {
        return $this->json(['error' => $message], $status);
    }

    private function log(?int $tokenId, ?string $ip, ?string $query, int $code, ?string $note): void
    {
        try {
            ApiRequestLog::create([
                'api_token_id' => $tokenId,
                'endpoint'     => self::ENDPOINT,
                'ip'           => $ip,
                'query_value'  => $query !== '' ? mb_substr($query, 0, 50) : null,
                'status_code'  => $code,
                'note'         => $note,
                'created_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            // บันทึก log ไม่สำเร็จ ไม่ให้กระทบการตอบกลับ
        }
    }
}