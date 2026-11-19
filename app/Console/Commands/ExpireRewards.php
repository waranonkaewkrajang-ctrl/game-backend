<?php

namespace App\Console\Commands;

use App\Models\Reward;
use Illuminate\Console\Command;

/**
 * เปลี่ยนรางวัลรอรับ (ยอดเสีย/ค่าแนะนำ) ที่เกินเวลารับแล้วเป็น expired
 *
 * ลูกค้ารับได้เฉพาะรายการที่ created_at >= ตอนนี้ − วันหมดอายุของประเภทนั้น (RewardController)
 * แต่ cashback:calculate รันวันละครั้งตอนรางวัลชุดเมื่อวานยังขาดอีกไม่กี่วินาทีจะครบเวลา
 * สถานะในฐานข้อมูลจึงค้าง "pending" ต่ออีกเกือบวัน (หน้าแอดมินนับเป็น "รอรับ" ทั้งที่ลูกค้ารับไม่ได้แล้ว)
 * คำสั่งนี้ใช้เกณฑ์เดียวกับฝั่งลูกค้าทุกประการ (Reward::expireStale) และรันถี่กว่า (crontab ทุกชั่วโมง) — ไม่ได้ตัดสิทธิ์เร็วขึ้น
 * วันหมดอายุ: ค่าแนะนำ = referral_expire_days (ถ้าตั้ง) ไม่งั้น reward_expire_days · อื่น ๆ = reward_expire_days
 */
class ExpireRewards extends Command
{
    protected $signature = 'rewards:expire';
    protected $description = 'เปลี่ยนรางวัลรอรับที่เกินเวลารับเป็นหมดอายุ (reward_expire_days / referral_expire_days)';

    public function handle(): int
    {
        $done = Reward::expireStale();
        $total = $done['referral'] + $done['other'];
        if ($total > 0) {
            $this->line(now()->toDateTimeString() . " หมดอายุ {$total} รายการ (ค่าแนะนำ {$done['referral']}, อื่น ๆ {$done['other']})");
        }
        return self::SUCCESS;
    }
}
