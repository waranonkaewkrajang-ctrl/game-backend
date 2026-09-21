<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpinWheelPrize extends Model
{
    protected $fillable = [
        'label', 'type', 'value', 'color', 'icon', 'image_url',
        'img_scale', 'img_x', 'img_y', 'img_rotate',
        'probability', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'value'       => 'decimal:2',
        'probability' => 'decimal:2',
        'is_active'   => 'boolean',
        'img_scale'   => 'integer',
        'img_x'       => 'integer',
        'img_y'       => 'integer',
        'img_rotate'  => 'integer',
    ];
}