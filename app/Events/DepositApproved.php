<?php

namespace App\Events;

use App\Models\Deposit;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DepositApproved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $deposit;

    public function __construct(Deposit $deposit)
    {
        $this->deposit = $deposit;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.' . $this->deposit->user_id);
    }

    public function broadcastAs(): string
    {
        return 'deposit.approved';
    }

    public function broadcastWith(): array
    {
        return [
            'id'              => $this->deposit->id,
            'reference_id'    => $this->deposit->reference_id,
            'amount'          => (float) $this->deposit->amount,
            'approved_method' => $this->deposit->approved_method,
            'approved_at'     => $this->deposit->approved_at?->toIso8601String(),
            'message'         => 'ฝากเงินสำเร็จ',
        ];
    }
}