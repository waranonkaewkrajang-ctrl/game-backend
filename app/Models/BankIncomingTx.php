<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankIncomingTx extends Model
{
    protected $table = 'bank_incoming_txs';

    protected $fillable = [
        'fingerprint', 'bank', 'amount', 'tx_time_raw', 'tx_at', 'from_account', 'from_account_4',
        'source', 'mode', 'status', 'reason', 'note', 'deposit_id', 'unmatched_deposit_id',
        'reported_by', 'seen_count', 'last_seen_at', 'processed_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'tx_at'        => 'datetime',
        'last_seen_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function deposit()
    {
        return $this->belongsTo(Deposit::class);
    }

    public function unmatchedDeposit()
    {
        return $this->belongsTo(UnmatchedDeposit::class);
    }
}
