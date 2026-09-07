<?php

namespace App\Observers;

use App\Models\Deposit;
use App\Events\AdminBadgeUpdated;

class DepositObserver
{
    public function created(Deposit $deposit): void
    {
        try {
            $pendingCount = Deposit::where('status', 'pending')->count();
            event(new AdminBadgeUpdated('deposit', $pendingCount));
        } catch (\Exception $e) {}
    }
}