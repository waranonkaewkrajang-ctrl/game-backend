<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Popup extends Model
{
    protected $fillable = [
        'title', 'description', 'image_url', 'link_url', 'link_text',
        'is_active', 'show_once', 'sort_order',
        'start_at', 'end_at', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'show_once' => 'boolean',
        'start_at'  => 'datetime',
        'end_at'    => 'datetime',
    ];

    /** Scope: popup ที่กำลังแสดงอยู่ */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('start_at')->orWhere('start_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_at')->orWhere('end_at', '>=', now());
            });
    }
}