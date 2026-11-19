<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Reward extends Model
{
    protected $fillable = [
        'user_id', 'type', 'amount', 'status', 'description', 'meta', 'claimed_at',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'meta'       => 'array',
        'claimed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function pendingAmount(int $userId, string $type): float
    {
        return (float) self::where('user_id', $userId)
            ->where('type', $type)
            ->where('status', 'pending')
            ->sum('amount');
    }

    /**
     * จำนวนวันที่ลูกค้ากดรับได้ของรางวัลแต่ละประเภท (0 = ไม่หมดอายุ)
     *
     * ค่าแนะนำเพื่อนใช้ `referral_expire_days` ถ้าแอดมินตั้งไว้ — เว้นว่าง = ใช้ `reward_expire_days` เหมือนเดิม
     * (ผู้แนะนำไม่ได้เข้าเว็บทุกวันเหมือนคนเล่น จึงแยกให้ตั้งนานกว่าได้)
     */
    public static function expireDaysFor(string $type): int
    {
        if ($type === 'referral') {
            $own = Setting::getValue('referral_expire_days');
            if ($own !== null && $own !== '' && is_numeric($own)) {
                return max(0, (int) $own);
            }
        }
        return max(0, (int) Setting::getValue('reward_expire_days', 0));
    }

    /**
     * เปลี่ยนรางวัลรอรับที่เกินเวลารับเป็น expired — เกณฑ์เดียวกับที่ลูกค้ากดรับ (created_at >= ตอนนี้ − วัน)
     *
     * @return array<string,int> จำนวนที่เปลี่ยนแยกตามกลุ่ม ['referral' => n, 'other' => n]
     */
    public static function expireStale(): array
    {
        $out = ['referral' => 0, 'other' => 0];

        $referralDays = self::expireDaysFor('referral');
        if ($referralDays > 0) {
            $out['referral'] = self::where('status', 'pending')->where('type', 'referral')
                ->where('created_at', '<', Carbon::now()->subDays($referralDays))
                ->update(['status' => 'expired']);
        }

        $otherDays = self::expireDaysFor('cashback');
        if ($otherDays > 0) {
            $out['other'] = self::where('status', 'pending')->where('type', '!=', 'referral')
                ->where('created_at', '<', Carbon::now()->subDays($otherDays))
                ->update(['status' => 'expired']);
        }

        return $out;
    }
}
