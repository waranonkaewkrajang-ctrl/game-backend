<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionClaim extends Model
{
    protected $fillable = [
        'user_id', 'promotion_id', 'deposit_id',
        'bonus_amount', 'turnover_required', 'turnover_current',
        'turnover_completed', 'status',
    ];

    protected $casts = [
        'bonus_amount'       => 'decimal:2',
        'turnover_required'  => 'decimal:2',
        'turnover_current'   => 'decimal:2',
        'turnover_completed' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function deposit()
    {
        return $this->belongsTo(Deposit::class);
    }
}