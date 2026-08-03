<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpinWheelMultiplier extends Model
{
    protected $fillable = [
        'label', 'value', 'color', 'probability', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'value'       => 'decimal:2',
        'probability' => 'decimal:2',
        'is_active'   => 'boolean',
    ];
}