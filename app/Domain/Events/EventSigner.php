<?php

namespace App\Domain\Events;

use Illuminate\Support\Str;

/**
 * HMAC-SHA256 ของ "{timestamp}.{nonce}.{rawBody}" — admin-api ตรวจด้วย secret เดียวกัน (constant-time)
 * ไม่ส่ง secret ไปกับ request, timestamp + nonce กัน replay
 */
class EventSigner
{
    public function configured(): bool
    {
        return (bool) config('domain_events.url') && strlen((string) config('domain_events.secret')) >= 32;
    }

    /** @return array<string,string> */
    public function headers(string $rawBody, ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp ??= time();
        $nonce ??= (string) Str::uuid();
        return [
            'Content-Type'       => 'application/json',
            'X-Event-Source'     => (string) config('domain_events.source', 'game-backend'),
            'X-Event-Key-Id'     => (string) config('domain_events.key_id', 'k1'),
            'X-Event-Timestamp'  => (string) $timestamp,
            'X-Event-Nonce'      => $nonce,
            'X-Event-Signature'  => self::sign((string) config('domain_events.secret'), $timestamp, $nonce, $rawBody),
        ];
    }

    public static function sign(string $secret, int $timestamp, string $nonce, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $rawBody, $secret);
    }
}
