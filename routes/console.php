<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 🆕 Auto cleanup bank_statements ที่เก่ากว่า 30 วัน (ทุกวันตี 4)
use Illuminate\Support\Facades\Schedule;

Schedule::command('statements:prune')->dailyAt('04:00');

// 🆕 domain events outbox — สแกนตาราง outbox "ในเครื่อง" เพื่อกู้ event ที่ยังไม่ถูกส่ง (ไม่ได้ดึงข้อมูลข้ามเซิร์ฟเวอร์)
// คำสั่งจะไม่ทำอะไรถ้า DOMAIN_EVENTS_ENABLED ไม่ใช่ true
Schedule::command('domain-events:dispatch')->everyMinute()->withoutOverlapping(5)->runInBackground();
Schedule::command('domain-events:prune')->dailyAt('04:30');