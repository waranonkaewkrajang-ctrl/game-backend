<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ThemeService
{
    // ค่าเริ่มต้น = สีเดิมของเว็บทั้งหมด (deploy แล้วหน้าตาไม่เปลี่ยน)
    public const DEFAULTS = [
        'primary'       => '#7c3aed',
        'primary_light' => '#a855f7',
        'accent'        => '#f59e0b',
        'accent_dark'   => '#d97706',
        'success'       => '#10b981',
        'danger'        => '#dc2626',
        'bg'            => '#0a0a14',
        'surface'       => '#14142a',
        'text'          => '#e2e8f0',
        'text_muted'    => '#94a3b8',
        'radius'        => 8,
        'btn_depth'     => 0,
        'glow'          => 40,
        'font'          => 'Inter',   // ค่าเดิมของเว็บ
        'font_scale'    => 100,       // ขนาดตัวอักษร % (100 = ปกติ)
    ];

    public const FONTS = ['Inter', 'Kanit', 'Prompt', 'Chakra Petch', 'Mitr', 'Bai Jamjuree', 'Sarabun', 'Noto Sans Thai'];

    public function get(): array
    {
        return Cache::remember('site_theme', 300, function () {
            $raw   = DB::table('settings')->where('key', 'site_theme')->value('value');
            $saved = $raw ? (json_decode($raw, true) ?: []) : [];
            return array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        });
    }

    public function save(array $data): array
    {
        $clean = array_intersect_key($data, self::DEFAULTS);

        DB::table('settings')->updateOrInsert(
            ['key' => 'site_theme'],
            [
                'value'       => json_encode($clean),
                'group'       => 'theme',
                'description' => 'ธีมสีและสไตล์หน้าเว็บลูกค้า',
                'updated_at'  => now(),
            ]
        );

        Cache::forget('site_theme');
        return $this->get();
    }
}