<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    protected $fillable = [
        'product_id', 'game_code', 'game_name', 'game_name_th',
        'category', 'type', 'image_url', 'rank', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}