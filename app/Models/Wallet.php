<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $fillable = [
        'user_id', 'balance', 'bonus_balance',
        'total_deposit', 'total_withdraw', 'total_bet', 'total_win',
    ];

    protected $casts = [
        'balance'        => 'decimal:2',
        'bonus_balance'  => 'decimal:2',
        'total_deposit'  => 'decimal:2',
        'total_withdraw' => 'decimal:2',
        'total_bet'      => 'decimal:2',
        'total_win'      => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function hasEnough(float $amount): bool
    {
        return $this->balance >= $amount;
    }
}