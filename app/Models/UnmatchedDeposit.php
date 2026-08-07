<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnmatchedDeposit extends Model
{
    protected $fillable = [
        'bank', 'amount', 'from_account', 'tx_time',
        'status', 'matched_user_id', 'approved_by', 'approved_at', 'note',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'matched_user_id');
    }
}