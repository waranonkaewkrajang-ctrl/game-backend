<?php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BankChangeRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $pendingCount;

    public function __construct($pendingCount)
    {
        $this->pendingCount = $pendingCount;
    }

    public function broadcastOn()
    {
        return new Channel('admin-notifications');
    }

    public function broadcastAs()
    {
        return 'bank-change';
    }
}