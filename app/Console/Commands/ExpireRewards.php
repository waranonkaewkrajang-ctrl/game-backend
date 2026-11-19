<?php

namespace App\Console\Commands;

use App\Models\Reward;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * เปลี่ยนรางวัลรอรับ (ยอดเสีย/ค่าแนะนำ) ที่เกินเวลารับแล้วเป็น expired
 *
 * ลูกค้ารับได้เฉพาะรายการที่ created_at >= ตอนนี้ − reward_expire_days (RewardController)
 * แต่ cashback:calculate รันวันละครั้งตอนรางวัลชุดเมื่อวานยังขาดอีกไม่กี่วินาทีจะครบเวลา
 * สถานะในฐานข้อมูลจึงค้าง "pending" ต่ออีกเกือบวัน (หน้าแอดมินนับเป็น "รอรับ" ทั้งที่ลูกค้ารับไม่ได้แล้ว)
 * คำสั่งนี้ใช้เกณฑ์เดียวกับฝั่งลูกค้าทุกประการ และรันถี่กว่า (crontab ทุกชั่วโมง) — ไม่ได้ตัดสิทธิ์เร็วขึ้น
 */
class ExpireRewards extends Command
{
    protected $signature = 'rewards:expire';
    protected $description = 'เปลี่ยนรางวัลรอรับที่เกินเวลารับ (reward_expire_days) เป็นหมดอายุ';

    public function handle(): int
    {
        $expireDays = (int) Setting::getValue('reward_expire_days', 0);
        if ($expireDays <= 0) {
            $this->line('ไม่ได้ตั้งวันหมดอายุ — ข้าม');
            return self::SUCCESS;
        }

        $count = Reward::where('status', 'pending')
            ->where('created_at', '<', Carbon::now()->subDays($expireDays))
            ->update(['status' => 'expired']);

        if ($count > 0) {
            $this->line(now()->toDateTimeString() . " หมดอายุ {$count} รายการ (เกิน {$expireDays} วัน)");
        }
        return self::SUCCESS;
    }
}
