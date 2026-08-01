<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TruewalletTransaction extends Model
{
    protected $fillable = [
        'transaction_id',
        'event_type',
        'amount',
        'sender_mobile',
        'receiver_mobile',
        'message',
        'status',
        'user_id',
        'deposit_id',
        'raw_data',
        'received_at',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'received_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function scopeMatched($query)
    {
        return $query->where('status', 'matched');
    }

    public function scopeUnmatched($query)
    {
        return $query->where('status', 'unmatched');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }
}