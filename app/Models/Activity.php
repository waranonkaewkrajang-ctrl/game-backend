<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Activity extends Model
{
    protected $fillable = [
        'type', 'title', 'subtitle',
        'image_url', 'image_thumb', 'canvas_data',
        'slots', 'pages', 'link_url',
        'sort_order', 'badge', 'badge_color',
        'is_active', 'start_at', 'end_at', 'audience', 'show_once',
        'config', 'created_by',
    ];

    protected $casts = [
        'slots'       => 'array',
        'pages'       => 'array',
        'config'      => 'array',
        'canvas_data' => 'array',
        'is_active'   => 'boolean',
        'show_once'   => 'boolean',
        'start_at'    => 'datetime',
        'end_at'      => 'datetime',
    ];

    /** เฉพาะกิจกรรมที่เปิดอยู่และถึงเวลาแสดง */
    public function scopeLive(Builder $q): Builder
    {
        $now = now();
        return $q->where('is_active', true)
            ->where(fn ($s) => $s->whereNull('start_at')->orWhere('start_at', '<=', $now))
            ->where(fn ($s) => $s->whereNull('end_at')->orWhere('end_at', '>=', $now));
    }

    public function entries()
    {
        return $this->hasMany(ActivityEntry::class);
    }

    /** ลูกค้าคนนี้เห็นกิจกรรมนี้ไหม */
    public function visibleTo(?User $user): bool
    {
        return match ($this->audience) {
            'member' => $user !== null,
            'guest'  => $user === null,
            default  => true,
        };
    }
}