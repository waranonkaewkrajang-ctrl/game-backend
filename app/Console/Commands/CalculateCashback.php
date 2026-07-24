<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Models\GameLog;
use App\Models\Reward;
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
        $expireDays = (int) Setting::getValue('reward_expire_days', 0);

        $this->info("วันที่ {$date->toDateString()} | คืนยอดเสีย {$cashbackPercent}% | ค่าแนะนำ {$referralPercent}% | หมดอายุ {$expireDays} วัน");

        // === ลบรางวัลที่หมดอายุ ===
        $this->expireOldRewards($expireDays);

        // เช็คว่าคำนวณวันนี้ไปแล้วหรือยัง (กัน duplicate)
        $alreadyCalculated = Reward::where('type', 'cashback')
            ->whereJsonContains('meta->date', $date->toDateString())
            ->exists();

        if ($alreadyCalculated) {
            $this->warn("วันที่ {$date->toDateString()} คำนวณไปแล้ว — ข้าม");
            return;
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

            // ยอดเสีย = bet - win (ถ้าติดลบ = ได้กำไร ไม่ต้องจ่าย)
            $loss = $totalBet - $totalWin;

            if ($loss <= 0) continue;

            // === 1. คำนวณ Cashback (คืนยอดเสียให้ตัวเอง) ===
            if ($cashbackPercent > 0) {
                $cashback = round($loss * ($cashbackPercent / 100), 2);

                if ($cashback >= 1) {
                    try {
                        Reward::create([
                            'user_id'     => $user->id,
                            'type'        => 'cashback',
                            'amount'      => $cashback,
                            'status'      => 'pending',
                            'description' => "คืนยอดเสีย {$cashbackPercent}% วันที่ {$date->toDateString()} (เสีย {$loss})",
                            'meta'        => [
                                'date'    => $date->toDateString(),
                                'loss'    => $loss,
                                'percent' => $cashbackPercent,
                                'bet'     => $totalBet,
                                'win'     => $totalWin,
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

                if ($referrer && $referrer->isActive()) {
                    $commission = round($loss * ($referralPercent / 100), 2);

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
                                    'loss'          => $loss,
                                    'percent'       => $referralPercent,
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
     * เปลี่ยนรางวัลที่เกินกำหนดเป็น expired
     */
    private function expireOldRewards(int $expireDays): void
    {
        if ($expireDays <= 0) {
            $this->line("  [Expire] ไม่ได้ตั้งวันหมดอายุ — ข้าม");
            return;
        }

        $cutoff = Carbon::now()->subDays($expireDays);

        $expiredCount = Reward::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->update(['status' => 'expired']);

        if ($expiredCount > 0) {
            $this->line("  [Expire] ลบรางวัลหมดอายุ {$expiredCount} รายการ (เกิน {$expireDays} วัน)");
        } else {
            $this->line("  [Expire] ไม่มีรางวัลหมดอายุ");
        }
    }
}