<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 🆕 Auto cleanup bank_statements ที่เก่ากว่า 30 วัน (ทุกวันตี 4)
use Illuminate\Support\Facades\Schedule;

Schedule::command('statements:prune')->dailyAt('04:00');