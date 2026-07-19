<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameLog extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'game_id', 'game_name', 'round_id',
        'action', 'bet_amount', 'win_amount',
        'balance_before', 'balance_after', 'raw_data',
    ];

    protected $casts = [
        'bet_amount'     => 'decimal:2',
        'win_amount'     => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after'  => 'decimal:2',
        'raw_data'       => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}