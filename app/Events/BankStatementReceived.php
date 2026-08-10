<?php

namespace App\Events;

use App\Models\BankStatement;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BankStatementReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $statement;

    public function __construct(BankStatement $statement)
    {
        $this->statement = $statement->load('user:id,username', 'admin:id,username');
    }

    public function broadcastOn(): Channel
    {
        return new Channel('admin-bank-statements');
    }

    public function broadcastAs(): string
    {
        return 'statement.received';
    }

    public function broadcastWith(): array
    {
        return [
            'id'               => $this->statement->id,
            'deposit_id'       => $this->statement->deposit_id,
            'user_id'          => $this->statement->user_id,
            'username'         => $this->statement->user?->username,
            'amount'           => (float) $this->statement->amount,
            'bank_code'        => $this->statement->bank_code,
            'from_name'        => $this->statement->from_name,
            'reference_id'     => $this->statement->reference_id,
            'approved_method'  => $this->statement->approved_method,
            'admin_username'   => $this->statement->admin?->username,
            'transaction_time' => $this->statement->transaction_time?->toIso8601String(),
            'created_at'       => $this->statement->created_at?->toIso8601String(),
        ];
    }
}