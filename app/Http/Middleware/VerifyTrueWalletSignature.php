<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * ตรวจลายเซ็น JWT (HS256) ของ TrueWallet webhook ก่อนถึง controller
 * เพื่อปิดทาง fallback $request->all() ใน TrueWalletWebhookController
 *
 * ใช้เงื่อนไขเดียวกับ controller ทุกจุด:
 *   - has('test') หรือ body ว่าง = ping → ปล่อยผ่าน (controller ตอบ ok แล้วจบ ไม่เติมเงิน)
 *   - token = json_decode(body)['message'] ?? body
 *   - ตรวจ exp / nbf / iat แบบ firebase/php-jwt (leeway 0) → VERIFIED ⇔ controller ถอดผ่านทาง JWT
 *
 * monitor = log แล้วปล่อยผ่าน | enforce = ไม่ผ่านตอบ 401
 */
class VerifyTrueWalletSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('truewallet.webhook_secret', '');
        $mode   = config('truewallet.webhook_mode', 'monitor');

        if (!in_array($mode, ['monitor', 'enforce'], true)) {
            $this->log('warning', 'CONFIG', $request, ['reason' => "TRUEWALLET_WEBHOOK_MODE ไม่ถูกต้อง ({$mode}) ใช้ monitor แทน"]);
            $mode = 'monitor';
        }

        $raw = $request->getContent();

        if ($request->has('test') || empty($raw)) {
            $this->log('info', 'PING', $request, ['mode' => $mode, 'action' => 'pass']);
            return $next($request);
        }

        $json   = json_decode($raw, true);
        $token  = is_array($json) ? ($json['message'] ?? $raw) : $raw;
        $format = is_array($json) ? (isset($json['message']) ? 'json_message' : 'plain_json') : 'raw';

        [$result, $payload, $error] = $this->verify(is_string($token) ? $token : '', $secret);

        // ข้อมูลไว้ดูใน log — ถ้าไม่ผ่านจะเป็นค่าที่ผู้เรียกอ้างมาเอง (ยังไม่ได้ตรวจ)
        $claimed = $payload ?? (is_array($json) && !isset($json['message']) ? $json : []);

        $context = [
            'mode'           => $mode,
            'format'         => $format,
            'error'          => $error,
            'transaction_id' => isset($claimed['transaction_id']) ? mb_substr((string) $claimed['transaction_id'], 0, 64) : null,
            'amount'         => is_numeric($claimed['amount'] ?? null) ? (float) $claimed['amount'] : null,
            'event_type'     => isset($claimed['event_type']) ? mb_substr((string) $claimed['event_type'], 0, 30) : null,
            'sender_tail'    => isset($claimed['sender_mobile']) && is_string($claimed['sender_mobile'])
                ? substr(preg_replace('/\D/', '', $claimed['sender_mobile']), -4) : null,
        ];

        if ($result === 'VERIFIED') {
            $this->log('info', 'VERIFIED', $request, $context + ['action' => 'pass']);
            return $next($request);
        }

        if ($mode === 'enforce') {
            $this->log($result === 'NO_SECRET' ? 'critical' : 'warning', $result, $request, $context + ['action' => 'block']);

            return response()->json(['status' => 'error'], 401);
        }

        $this->log($result === 'NO_SECRET' ? 'critical' : 'warning', $result, $request, $context + ['action' => 'pass']);

        return $next($request);
    }

    /** @return array{0: string, 1: ?array, 2: ?string} [ผล, payload ที่ตรวจแล้ว, เหตุผล] */
    private function verify(string $token, string $secret): array
    {
        if ($secret === '') {
            return ['NO_SECRET', null, 'ไม่ได้ตั้ง TRUEWALLET_WEBHOOK_SECRET'];
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return ['UNSIGNED', null, 'ไม่ใช่ JWT'];
        }

        [$h, $p, $s] = $parts;
        $header = json_decode((string) $this->b64($h), true);

        if (($header['alg'] ?? null) !== 'HS256') {
            return ['BAD_SIGNATURE', null, 'alg ไม่ใช่ HS256'];
        }

        $sig = $this->b64($s);
        if ($sig === null || !hash_equals(hash_hmac('sha256', "{$h}.{$p}", $secret, true), $sig)) {
            return ['BAD_SIGNATURE', null, 'ลายเซ็นไม่ตรง'];
        }

        $payload = json_decode((string) $this->b64($p), true);
        if (!is_array($payload)) {
            return ['BAD_SIGNATURE', null, 'payload อ่านไม่ได้'];
        }

        $now = time();
        if (isset($payload['nbf']) && $payload['nbf'] > $now) {
            return ['BAD_SIGNATURE', null, 'nbf ยังไม่ถึง'];
        }
        if (!isset($payload['nbf']) && isset($payload['iat']) && $payload['iat'] > $now) {
            return ['BAD_SIGNATURE', null, 'iat อยู่ในอนาคต'];
        }
        if (isset($payload['exp']) && $now >= $payload['exp']) {
            return ['BAD_SIGNATURE', null, 'หมดอายุ (exp)'];
        }

        return ['VERIFIED', $payload, null];
    }

    private function b64(string $s): ?string
    {
        $decoded = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }

    private function clientIp(Request $request): ?string
    {
        $remote  = $request->server('REMOTE_ADDR');
        $proxies = (array) config('truewallet.trusted_proxies', []);

        if (!$remote || $proxies === [] || !IpUtils::checkIp($remote, $proxies)) {
            return $remote ?: null;
        }

        $chain = array_filter(array_map('trim', explode(',', implode(',', $request->headers->all('x-forwarded-for')))));

        foreach (array_reverse($chain) as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return null;
            }
            if (!IpUtils::checkIp($ip, $proxies)) {
                return $ip;
            }
        }

        return $remote;
    }

    private function log(string $level, string $result, Request $request, array $context): void
    {
        try {
            Log::channel('truewallet_webhook')->log($level, $result, $context + [
                'client_ip'   => $this->clientIp($request),
                'remote_addr' => $request->server('REMOTE_ADDR'),
            ]);
        } catch (\Throwable $e) {
            // log พังต้องไม่ทำให้ webhook พัง
        }
    }
}
