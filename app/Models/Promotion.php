<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'description', 'image_url', 'type',
        'min_deposit', 'max_bonus', 'bonus_percent',
        'turnover_multiplier','allowed_categories', 'max_withdraw',
        'is_active', 'max_claims', 'claims_per_user',
        'start_at', 'end_at',
    ];

    protected $casts = [
        'allowed_categories' => 'array',
        'min_deposit'         => 'decimal:2',
        'max_bonus'           => 'decimal:2',
        'bonus_percent'       => 'decimal:2',
        'turnover_multiplier' => 'decimal:2',
        'max_withdraw'        => 'decimal:2',
        'is_active'           => 'boolean',
        'start_at'            => 'datetime',
        'end_at'              => 'datetime',
    ];

    public function claims()
    {
        return $this->hasMany(PromotionClaim::class);
    }
}