<?php

/*
| Domain events → admin-api (Push + Transactional Outbox)
| ปิดเป็นค่าเริ่มต้น: ถ้าไม่ตั้ง DOMAIN_EVENTS_ENABLED=true จะไม่มีการเขียน outbox และไม่ส่งอะไรออก
*/
return [
    'enabled'  => env('DOMAIN_EVENTS_ENABLED', false),

    // ปลายทางของ admin-api (ต้องเป็น https บน production) เช่น https://admin-api.example/internal/events
    'url'      => env('DOMAIN_EVENTS_URL'),
    'import_url' => env('DOMAIN_EVENTS_IMPORT_URL'), // ว่าง = {url}/import
    'key_id'   => env('DOMAIN_EVENTS_KEY_ID', 'k1'),
    'secret'   => env('DOMAIN_EVENTS_SECRET'),         // อย่างน้อย 32 ตัวอักษร (HMAC-SHA256)
    'source'   => env('DOMAIN_EVENTS_SOURCE', 'game-backend'),

    'batch_size'      => (int) env('DOMAIN_EVENTS_BATCH', 50),
    'lease_seconds'   => (int) env('DOMAIN_EVENTS_LEASE_SECONDS', 60),
    'connect_timeout' => (int) env('DOMAIN_EVENTS_CONNECT_TIMEOUT', 3),
    'timeout'         => (int) env('DOMAIN_EVENTS_TIMEOUT', 10),
    'max_attempts'    => (int) env('DOMAIN_EVENTS_MAX_ATTEMPTS', 25),   // เกินนี้ = dead (สั่ง replay ได้)
    'backoff_base_seconds' => (int) env('DOMAIN_EVENTS_BACKOFF_BASE', 5),
    'backoff_max_seconds'  => (int) env('DOMAIN_EVENTS_BACKOFF_MAX', 3600),
    'retention_days'  => (int) env('DOMAIN_EVENTS_RETENTION_DAYS', 14),  // ลบเฉพาะที่ delivered แล้ว
    'alert_after_minutes' => (int) env('DOMAIN_EVENTS_ALERT_AFTER_MINUTES', 10),

    // การบันทึกกิจกรรมสมาชิก (throttle เพื่อไม่ให้เขียน DB ทุก request)
    'activity' => [
        'write_every_minutes' => (int) env('MEMBER_ACTIVITY_WRITE_MINUTES', 5),
        'event_every_minutes' => (int) env('MEMBER_ACTIVITY_EVENT_MINUTES', 360),
    ],

    // ตามลูกค้าด้วยการ์ดเกมที่เล่น (crm:publish-play) — เวลาเล่นล่าสุด + เกมที่เล่นบ่อย ส่งไป CRM
    'play' => [
        'games'               => (int) env('CRM_PLAY_GAMES', 5),             // จำนวนเกมที่ส่งต่อคน (1–9)
        'scan_bets'           => (int) env('CRM_PLAY_SCAN_BETS', 300),       // ดูจากการเดิมพันล่าสุดกี่ครั้ง
        'event_every_minutes' => (int) env('CRM_PLAY_EVENT_MINUTES', 360),   // ส่งซ้ำเมื่อรายชื่อเกมเดิม ทุกกี่นาที
    ],
];
