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
    protected $description = 'คำนวณยอดเสียรายวันแล้วเก็บเป็นรางวัลรอรับ';

    public function handle()
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::yesterday();

        $percent = (float) Setting::getValue('cashback_percent', 0);

        if ($percent <= 0) {
            $this->warn("cashback_percent = 0 ไม่มีการจ่ายคืน");
            return;
        }

        $this->info("คำนวณยอดเสีย วันที่ {$date->toDateString()} | คืน {$percent}%");

        // เช็คว่าคำนวณวันนี้ไปแล้วหรือยัง (กัน duplicate)
        $alreadyCalculated = Reward::where('type', 'cashback')
            ->whereJsonContains('meta->date', $date->toDateString())
            ->exists();

        if ($alreadyCalculated) {
            $this->warn("วันที่ {$date->toDateString()} คำนวณไปแล้ว — ข้าม");
            return;
        }

        $users = User::where('status', 'active')->get();
        $totalPaid = 0;
        $count = 0;

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

            // คำนวณ cashback
            $cashback = round($loss * ($percent / 100), 2);
            if ($cashback < 1) continue; // ต่ำกว่า 1 บาทไม่จ่าย

            try {
                // เก็บเป็นรางวัลรอรับ (ไม่จ่ายตรงเข้ากระเป๋า)
                Reward::create([
                    'user_id'     => $user->id,
                    'type'        => 'cashback',
                    'amount'      => $cashback,
                    'status'      => 'pending',
                    'description' => "คืนยอดเสีย {$percent}% วันที่ {$date->toDateString()} (เสีย {$loss})",
                    'meta'        => [
                        'date'    => $date->toDateString(),
                        'loss'    => $loss,
                        'percent' => $percent,
                        'bet'     => $totalBet,
                        'win'     => $totalWin,
                    ],
                ]);
                $totalPaid += $cashback;
                $count++;
                $this->line("  {$user->username}: เสีย {$loss} → รอรับ {$cashback}");
            } catch (\Exception $e) {
                Log::error("Cashback failed for user {$user->id}: " . $e->getMessage());
                $this->error("  {$user->username}: ERROR - {$e->getMessage()}");
            }
        }

        $this->info("เสร็จสิ้น: สร้างรางวัลรอรับ {$count} คน รวม ฿{$totalPaid}");
    }
}