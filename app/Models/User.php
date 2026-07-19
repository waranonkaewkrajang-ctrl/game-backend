<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $fillable = [
        'username', 'phone', 'password', 'full_name', 'line_id',
        'bank_code', 'bank_account', 'bank_name',
        'status', 'is_verified', 'referral_code', 'referred_by',
        'last_login_ip', 'last_login_at',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'is_verified'   => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function deposits()
    {
        return $this->hasMany(Deposit::class);
    }

    public function withdrawals()
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function gameLogs()
    {
        return $this->hasMany(GameLog::class);
    }

    public function promotionClaims()
    {
        return $this->hasMany(PromotionClaim::class);
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function referrals()
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getBalance(): float
    {
        return $this->wallet?->balance ?? 0;
    }
}