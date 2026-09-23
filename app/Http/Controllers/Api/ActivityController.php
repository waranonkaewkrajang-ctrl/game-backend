<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ActivityController extends Controller
{
    /**
     * กิจกรรมที่แสดงบนหน้าเว็บลูกค้า
     * ?page=home  → เอาเฉพาะที่สั่งให้โผล่หน้านั้น
     */
    public function index(Request $request): JsonResponse
    {
        $page = (string) $request->query('page', '');
        $user = $request->user();

        // cache 60 วินาที — ลูกค้าหลายคนใช้ชุดเดียวกัน ไม่ต้องถามฐานข้อมูลทุกครั้ง
        $all = Cache::remember('site_activities', 60, function () {
            return Activity::live()
                ->orderBy('sort_order')->orderByDesc('id')
                ->get([
                    'id', 'type', 'title', 'subtitle', 'image_url', 'image_thumb',
                    'slots', 'pages', 'link_url', 'badge', 'badge_color',
                    'audience', 'show_once', 'config', 'sort_order',
                ]);
        });

        $items = $all
            ->filter(fn ($a) => $a->visibleTo($user))
            ->filter(fn ($a) => $page === '' || empty($a->pages) || in_array($page, (array) $a->pages, true))
            ->map(fn ($a) => [
                'id'          => $a->id,
                'type'        => $a->type,
                'title'       => $a->title,
                'subtitle'    => $a->subtitle,
                'image_url'   => $a->image_url,
                'image_thumb' => $a->image_thumb ?: $a->image_url,
                'slots'       => $a->slots,
                'link_url'    => $a->link_url,
                'badge'       => $a->badge,
                'badge_color' => $a->badge_color,
                'show_once'   => $a->show_once,
                'config'      => $this->publicConfig($a),
            ])
            ->values();

        return response()->json(['status' => 'success', 'data' => $items]);
    }

    /** นับจำนวนคลิก (ไม่บังคับให้ล็อกอิน) */
    public function click(int $id): JsonResponse
    {
        Activity::where('id', $id)->increment('click_count');
        return response()->json(['status' => 'success']);
    }

    /**
     * ตัดค่าที่ลูกค้าไม่ควรเห็นออกจาก config
     * เช่น เฉลยหวย/ผลบอลที่แอดมินกรอกไว้ล่วงหน้า
     */
    private function publicConfig(Activity $a): array
    {
        $config = (array) $a->config;
        foreach (['answer', 'result', 'winning_number', 'correct'] as $secret) {
            unset($config[$secret]);
        }
        return $config;
    }
}