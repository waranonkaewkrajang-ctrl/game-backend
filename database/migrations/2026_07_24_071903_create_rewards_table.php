<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reward extends Model
{
    protected $fillable = [
        'user_id', 'type', 'amount', 'status', 'description', 'meta', 'claimed_at',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'meta'       => 'array',
        'claimed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function pendingAmount(int $userId, string $type): float
    {
        return (float) self::where('user_id', $userId)
            ->where('type', $type)
            ->where('status', 'pending')
            ->sum('amount');
    }
}