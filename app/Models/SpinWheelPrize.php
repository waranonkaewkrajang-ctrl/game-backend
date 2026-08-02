<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpinWheelPrize extends Model
{
    protected $fillable = [
        'label', 'type', 'value', 'color', 'icon',
        'probability', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'value'       => 'decimal:2',
        'probability' => 'decimal:2',
        'is_active'   => 'boolean',
    ];
}