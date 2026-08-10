<?php

namespace App\Console\Commands;

use App\Models\BankStatement;
use Illuminate\Console\Command;

class PruneBankStatements extends Command
{
    protected $signature = 'statements:prune {--days=30 : จำนวนวันย้อนหลังที่จะเก็บ}';
    protected $description = 'ลบ bank_statements ที่เก่ากว่า X วัน (default 30)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = BankStatement::where('created_at', '<', $cutoff)->delete();

        $this->info("✅ ลบ bank_statements เก่ากว่า {$days} วันแล้ว: {$deleted} รายการ");
        \Log::info("PruneBankStatements: deleted {$deleted} rows older than {$days} days");

        return self::SUCCESS;
    }
}