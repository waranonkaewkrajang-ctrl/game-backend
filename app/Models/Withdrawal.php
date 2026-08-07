<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Withdrawal extends Model
{
    protected $fillable = [
        'user_id', 'reference_id', 'amount',
        'to_bank', 'to_account', 'to_name',
        'status', 'reject_reason',
        'balance_before', 'balance_after',
        'approved_by', 'approved_at',
        'processing_by', 'processing_at',
    ];
    protected $casts = [
        'amount'         => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after'  => 'decimal:2',
        'approved_at'    => 'datetime',
        'processing_at'  => 'datetime',
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function approver()
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }
    public function processor()
    {
        return $this->belongsTo(Admin::class, 'processing_by');
    }
}