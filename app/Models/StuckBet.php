<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StuckBet extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'round_id', 'txn_id',
        'bet_amount', 'amb_payout', 'amb_status',
        'status', 'refund_amount', 'refunded_by', 'refunded_at',
        'note', 'bet_at',
    ];

    protected $casts = [
        'bet_amount'    => 'decimal:2',
        'amb_payout'    => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'bet_at'        => 'datetime',
        'refunded_at'   => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** ยอดที่ควรคืนให้ลูกค้า — ถ้าค่ายบอกมาใช้ค่านั้น ถ้าไม่มีให้คืนเท่าที่หักไป */
    public function suggestedRefund(): float
    {
        if ($this->amb_payout !== null && (float) $this->amb_payout > 0) {
            return (float) $this->amb_payout;
        }
        return (float) $this->bet_amount;
    }
}