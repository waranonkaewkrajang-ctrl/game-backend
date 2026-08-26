<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionClaim extends Model
{
    protected $fillable = [
    'user_id', 'promotion_id', 'deposit_id',
    'type',
    'bonus_amount', 'turnover_multiplier',
    'turnover_required', 'turnover_current',
    'turnover_completed', 'status',
    'granted_by', 'completed_at', 'expired_at', 'note',
];

    protected $casts = [
    'bonus_amount'        => 'decimal:2',
    'turnover_multiplier' => 'decimal:2',
    'turnover_required'   => 'decimal:2',
    'turnover_current'    => 'decimal:2',
    'turnover_completed'  => 'boolean',
    'completed_at'        => 'datetime',
    'expired_at'          => 'datetime',
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

    // ============ เพิ่มตรงนี้ ============

    public function grantedBy()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /** Scope: เทิร์นที่ยังไม่ครบ */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                     ->where('turnover_completed', false);
    }

    /** เทิร์นเหลือกี่บาท */
    public function getRemainingAttribute(): float
    {
        return max(0, (float) bcsub(
            $this->turnover_required,
            $this->turnover_current,
            2
        ));
    }

    /** % ที่ทำไปแล้ว */
    public function getProgressPercentAttribute(): float
    {
        if ($this->turnover_required <= 0) return 100;
        return min(100, round(
            ($this->turnover_current / $this->turnover_required) * 100, 2
        ));
    }

    /** เช็คหมดอายุ */
    public function isExpired(): bool
    {
        return $this->expired_at && now()->gte($this->expired_at);
    }
}