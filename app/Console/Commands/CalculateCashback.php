<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Models\GameLog;
use App\Models\Reward;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CalculateCashback extends Command
{
    protected $signature = 'cashback:calculate {--date= : วันที่ต้องการคำนวณ (YYYY-MM-DD) ถ้าไม่ใส่จะใช้เมื่อวาน}';
    protected $description = 'คำนวณยอดเสียรายวัน (cashback + referral) แล้วเก็บเป็นรางวัลรอรับ + ลบรางวัลหมดอายุ';

    public function handle()
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::yesterday();

        $cashbackPercent = (float) Setting::getValue('cashback_percent', 0);
        $referralPercent = (float) Setting::getValue('referral_percent', 0);
        $day = $date->toDateString();

        $this->info("วันที่ {$day} | คืนยอดเสีย {$cashbackPercent}% | ค่าแนะนำ {$referralPercent}% | หมดอายุ ยอดเสีย " . Reward::expireDaysFor('cashback') . " วัน / ค่าแนะนำ " . Reward::expireDaysFor('referral') . " วัน");

        // === เปลี่ยนรางวัลที่หมดอายุ (เกณฑ์เดียวกับ rewards:expire) ===
        $done = Reward::expireStale();
        $this->line("  [Expire] ค่าแนะนำ {$done['referral']} รายการ, อื่น ๆ {$done['other']} รายการ");

        // กันจ่ายซ้ำ: เช็คทีละรายการ (ลูกค้า+วัน สำหรับยอดเสีย, ผู้แนะนำ+เพื่อน+วัน สำหรับค่าแนะนำ)
        // เดิมเช็คแค่ "มี cashback ของวันนี้แล้วหรือยัง" — วันที่ไม่มีใครได้ยอดเสียเลย รันซ้ำแล้วค่าแนะนำถูกสร้างซ้ำ
        $cashbackDone = Reward::where('type', 'cashback')->where('meta->date', $day)->pluck('user_id')->flip();
        $referralDone = Reward::where('type', 'referral')->where('meta->date', $day)
            ->get(['user_id', 'meta'])
            ->mapWithKeys(fn ($r) => [$r->user_id . ':' . ($r->meta['from_user_id'] ?? '') => true]);
        if ($cashbackDone->isNotEmpty() || $referralDone->isNotEmpty()) {
            $this->warn("วันที่ {$day} เคยคำนวณแล้วบางส่วน — สร้างเฉพาะรายการที่ยังไม่มี");
        }

        $users = User::where('status', 'active')->get();
        $cashbackCount = 0;
        $cashbackTotal = 0;
        $referralCount = 0;
        $referralTotal = 0;

        foreach ($users as $user) {
            // รวม bet ทั้งหมดของวันนั้น
            $totalBet = GameLog::where('user_id', $user->id)
                ->where('action', 'bet')->whereDate('created_at', $date)->sum('bet_amount');

            // รวม win ทั้งหมดของวันนั้น
            $totalWin = GameLog::where('user_id', $user->id)
                ->where('action', 'win')->whereDate('created_at', $date)->sum('win_amount');

            // เงินโบนัสที่รับวันนั้น (ยอดเสีย/ค่าแนะนำ/เครดิตฟรี/กงล้อ)
            // ไม่นับเป็นยอดเสีย เพราะไม่ใช่เงินฝากของลูกค้า
            $bonusReceived = (float) Transaction::where('user_id', $user->id)
                ->where('type', 'bonus')
                ->whereDate('created_at', $date)
                ->sum('amount');

            // ยอดเสียที่ได้คืน = (เดิมพัน − ชนะ) − โบนัสที่รับวันนั้น
            $rawLoss = $totalBet - $totalWin;
            $loss    = round($rawLoss - $bonusReceived, 2);

            if ($loss <= 0) continue;

            // === 1. คำนวณ Cashback (คืนยอดเสียให้ตัวเอง) ===
            if ($cashbackPercent > 0 && !$cashbackDone->has($user->id)) {
                $cashback = self::percentOf($loss, $cashbackPercent);

                if ($cashback >= 1) {
                    try {
                        Reward::create([
                            'user_id'     => $user->id,
                            'type'        => 'cashback',
                            'amount'      => $cashback,
                            'status'      => 'pending',
                            'description' => "คืนยอดเสีย {$cashbackPercent}% วันที่ {$date->toDateString()} (เสีย {$loss})",
                            'meta'        => [
                                'date'           => $date->toDateString(),
                                'loss'           => $loss,
                                'raw_loss'       => $rawLoss,
                                'bonus_received' => $bonusReceived,
                                'percent'        => $cashbackPercent,
                                'bet'            => $totalBet,
                                'win'            => $totalWin,

                            ],
                        ]);
                        $cashbackTotal += $cashback;
                        $cashbackCount++;
                        $this->line("  [Cashback] {$user->username}: เสีย {$loss} → รอรับ {$cashback}");
                    } catch (\Exception $e) {
                        Log::error("Cashback failed for user {$user->id}: " . $e->getMessage());
                    }
                }
            }

            // === 2. คำนวณ Referral (จ่ายค่าแนะนำให้คนที่แนะนำ user คนนี้) ===
            if ($referralPercent > 0 && $user->referred_by) {
                $referrer = User::find($user->referred_by);

                if ($referrer && $referrer->isActive() && !$referralDone->has($referrer->id . ':' . $user->id)) {
                    $commission = self::percentOf($loss, $referralPercent);

                    if ($commission >= 1) {
                        try {
                            Reward::create([
                                'user_id'     => $referrer->id,
                                'type'        => 'referral',
                                'amount'      => $commission,
                                'status'      => 'pending',
                                'description' => "ค่าแนะนำ {$user->username} ยอดเสีย {$loss} ({$referralPercent}%)",
                                'meta'        => [
                                    'date'          => $date->toDateString(),
                                    'from_user_id'  => $user->id,
                                    'from_username' => $user->username,
                                    'loss'           => $loss,
                                    'raw_loss'       => $rawLoss,
                                    'bonus_received' => $bonusReceived,
                                    'percent'        => $referralPercent,
                                    'bet'           => $totalBet,
                                    'win'           => $totalWin,
                                ],
                            ]);
                            $referralTotal += $commission;
                            $referralCount++;
                            $this->line("  [Referral] {$referrer->username} ← แนะนำ {$user->username} เสีย {$loss} → รอรับ {$commission}");
                        } catch (\Exception $e) {
                            Log::error("Referral failed for referrer {$referrer->id}: " . $e->getMessage());
                        }
                    }
                }
            }
        }

        $this->info("--- สรุป ---");
        $this->info("Cashback: {$cashbackCount} คน รวม ฿{$cashbackTotal}");
        $this->info("Referral: {$referralCount} คน รวม ฿{$referralTotal}");
    }

    /**
     * % ของยอดเงิน ปัดครึ่งขึ้นที่ 2 ตำแหน่ง คิดเป็นสตางค์ (จำนวนเต็ม)
     * เดิม round($loss * 0.05, 2) ให้ 154.70 × 5% = 7.73 เพราะ 7.735 เก็บในทศนิยมเป็น 7.73499… → ตอนนี้ได้ 7.74
     */
    public static function percentOf(float $amount, float $percent): float
    {
        $satang = (int) round($amount * 100);
        return round(round($satang * $percent, 6) / 100) / 100;
    }
}
