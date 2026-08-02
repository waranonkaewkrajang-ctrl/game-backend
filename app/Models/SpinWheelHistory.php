<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpinWheelHistory extends Model
{
    protected $table = 'spin_wheel_history';

    protected $fillable = [
        'user_id', 'prize_id', 'prize_label',
        'prize_type', 'prize_value', 'is_claimed',
    ];

    protected $casts = [
        'prize_value' => 'decimal:2',
        'is_claimed'  => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(SpinWheelPrize::class, 'prize_id');
    }
}