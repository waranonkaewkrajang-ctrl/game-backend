<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiToken extends Model
{
    protected $fillable = [
        'name', 'token_hash', 'token_prefix', 'allowed_ips',
        'is_active', 'last_used_at', 'last_used_ip', 'calls_count',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = ['token_hash'];

    /** เข้ารหัสทางเดียว — เก็บลงฐานข้อมูลแบบนี้ */
    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** IP นี้เรียกได้ไหม (ว่าง = อนุญาตทุก IP) */
    public function allowsIp(?string $ip): bool
    {
        $list = array_filter(array_map('trim', explode(',', (string) $this->allowed_ips)));
        return empty($list) || ($ip && in_array($ip, $list, true));
    }
}