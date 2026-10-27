<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * ตรวจว่า callback ค่ายเกมมาจาก IP ของ AMB จริง (config/amb.php)
 *
 * อ่าน IP เองโดยไม่พึ่ง trustProxies(at: '*') ที่ปลอมได้:
 *   - REMOTE_ADDR ไม่ใช่ proxy ที่เชื่อ → ใช้ REMOTE_ADDR
 *   - เป็น proxy ที่เชื่อ → ไล่ X-Forwarded-For จากขวาไปซ้าย เอาตัวแรกที่ไม่ใช่ proxy
 *     (ค่าที่ผู้เรียกยัดมาทางซ้ายจะไม่ถูกอ่าน)
 *
 * monitor = log แล้วปล่อยผ่าน | enforce = ไม่ผ่านตอบ 403
 */
class VerifyAmbCallbackIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = (array) config('amb.callback_ips', []);
        $mode    = config('amb.callback_ip_mode', 'monitor');

        if (!in_array($mode, ['monitor', 'enforce'], true)) {
            $this->log('warning', 'CONFIG', $request, null, ['reason' => "AMB_CALLBACK_IP_MODE ไม่ถูกต้อง ({$mode}) ใช้ monitor แทน"]);
            $mode = 'monitor';
        }

        $clientIp = $this->clientIp($request);

        if ($allowed !== [] && $clientIp !== null && IpUtils::checkIp($clientIp, $allowed)) {
            $this->log('info', 'PASS', $request, $clientIp, ['mode' => $mode]);
            return $next($request);
        }

        $context = [
            'mode'   => $mode,
            'reason' => $allowed === [] ? 'whitelist ว่าง (ไม่ได้ตั้ง AMB_CALLBACK_IPS)' : 'IP ไม่อยู่ใน whitelist',
        ];

        if ($mode === 'enforce') {
            $this->log($allowed === [] ? 'critical' : 'warning', 'BLOCK', $request, $clientIp, $context);

            return response()->json(['status' => 'error', 'message' => 'forbidden'], 403);
        }

        $this->log('warning', 'FAIL', $request, $clientIp, $context);

        return $next($request);
    }

    private function clientIp(Request $request): ?string
    {
        $remote  = $request->server('REMOTE_ADDR');
        $proxies = (array) config('amb.trusted_proxies', []);

        if (!$remote || $proxies === [] || !IpUtils::checkIp($remote, $proxies)) {
            return $remote ?: null;
        }

        $chain = array_filter(array_map('trim', explode(',', implode(',', $request->headers->all('x-forwarded-for')))));

        foreach (array_reverse($chain) as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return null;                                 // header เพี้ยน → ไม่เชื่อ
            }
            if (!IpUtils::checkIp($ip, $proxies)) {
                return $ip;
            }
        }

        return $remote;                                      // ไม่มี X-Forwarded-For → ได้ IP ของ proxy เอง
    }

    private function log(string $level, string $result, Request $request, ?string $clientIp, array $extra = []): void
    {
        try {
            $username = $request->input('username');

            Log::channel('amb_callback')->log($level, $result, $extra + [
                'client_ip'   => $clientIp,
                'remote_addr' => $request->server('REMOTE_ADDR'),
                'xff'         => $request->headers->get('x-forwarded-for'),
                'laravel_ip'  => $request->ip(),
                'path'        => $request->path(),
                'username'    => is_string($username) ? mb_substr($username, 0, 50) : null,
            ]);
        } catch (\Throwable $e) {
            // log พังต้องไม่ทำให้ callback พัง
        }
    }
}
