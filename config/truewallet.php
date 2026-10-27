<?php

return [
    // secret สำหรับตรวจ JWT (HS256) ของ TrueWallet webhook
    'webhook_secret'  => env('TRUEWALLET_WEBHOOK_SECRET', ''),

    // monitor = log อย่างเดียวแล้วปล่อยผ่าน | enforce = ไม่มีลายเซ็นถูกต้องตอบ 401
    'webhook_mode'    => env('TRUEWALLET_WEBHOOK_MODE', 'monitor'),

    // proxy หน้าแอปที่เชื่อ X-Forwarded-For ได้ (ใช้แค่ตอน log IP ผู้เรียก)
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUEWALLET_TRUSTED_PROXIES', '127.0.0.1'))))),
];
