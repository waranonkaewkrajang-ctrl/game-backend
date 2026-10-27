<?php

return [
    'api_url'        => env('AMB_API_URL', 'https://test.ambsuperapi.com'),
    'agent_username' => env('AMB_AGENT_USERNAME', ''),
    'api_key'        => env('AMB_API_KEY', ''),

    // IP ที่อนุญาตให้ยิง /api/game/callback/* (คั่นด้วย , รองรับ CIDR)
    'callback_ips'     => array_values(array_filter(array_map('trim', explode(',', (string) env('AMB_CALLBACK_IPS', ''))))),

    // monitor = log อย่างเดียวแล้วปล่อยผ่าน | enforce = ไม่อยู่ใน whitelist ตอบ 403
    'callback_ip_mode' => env('AMB_CALLBACK_IP_MODE', 'monitor'),

    // proxy หน้าแอปที่เชื่อ X-Forwarded-For ได้ (nginx บนเครื่องเดียวกัน)
    'trusted_proxies'  => array_values(array_filter(array_map('trim', explode(',', (string) env('AMB_TRUSTED_PROXIES', '127.0.0.1'))))),
];