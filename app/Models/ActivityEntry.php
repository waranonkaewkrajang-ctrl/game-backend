<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityEntry extends Model
{
    protected $fillable = [
        'activity_id', 'user_id', 'round_key', 'answer',
        'status', 'reward_amount', 'reward_type', 'settled_at',
    ];

    protected $casts = [
        'reward_amount' => 'decimal:2',
        'settled_at'    => 'datetime',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}