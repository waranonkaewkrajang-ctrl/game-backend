<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    protected $fillable = [
        'user_id', 'reference_id', 'amount',
        'channel', 'from_bank', 'from_account', 'to_bank', 'to_account',
        'slip_url', 'status', 'reject_reason',
        'approved_by', 'approved_at', 'promotion_id',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }
}