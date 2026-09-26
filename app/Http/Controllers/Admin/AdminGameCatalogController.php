<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * รายการค่าย/เกม สำหรับหน้าตั้งค่าโปรโมชัน — อ่านอย่างเดียว
 */
class AdminGameCatalogController extends Controller
{
    private const CATEGORY_NAMES = [
        'EGAMES'     => 'สล็อต / เกมตู้',
        'LIVECASINO' => 'คาสิโนสด',
        'CARD'       => 'เกมไพ่',
        'SPORT'      => 'กีฬา',
        'TRADING'    => 'ไก่ชน',
    ];

    /** รายชื่อค่ายทั้งหมด พร้อมไอคอน (ใช้รูปเกมแรกของค่าย) */
    public function providers(): JsonResponse
    {
        $rows = Cache::remember('admin_game_providers', 600, function () {
            return DB::table('games as g')
                ->select('g.product_id', 'g.category')
                ->selectRaw('COUNT(*) as game_count')
                ->selectRaw('MIN(g.image_url) as icon')
                ->groupBy('g.product_id', 'g.category')
                ->orderBy('g.category')
                ->orderByDesc('game_count')
                ->get();
        });

        $data = $rows->map(fn ($r) => [
            'product_id'    => $r->product_id,
            'category'      => $r->category,
            'category_name' => self::CATEGORY_NAMES[$r->category] ?? $r->category,
            'game_count'    => (int) $r->game_count,
            'icon'          => $r->icon,
        ]);

        return response()->json([
            'status'     => 'success',
            'data'       => $data,
            'categories' => collect(self::CATEGORY_NAMES)->map(fn ($n, $k) => ['key' => $k, 'name' => $n])->values(),
        ]);
    }

    /** ค้นหาเกม — ส่งทีละ 60 รายการ */
    public function games(Request $request): JsonResponse
    {
        $search   = trim((string) $request->query('search', ''));
        $provider = trim((string) $request->query('provider', ''));
        $category = trim((string) $request->query('category', ''));

        $rows = DB::table('games')
            ->when($search !== '', fn ($q) => $q->where(function ($s) use ($search) {
                $s->where('game_name', 'like', "%{$search}%")
                  ->orWhere('game_name_th', 'like', "%{$search}%")
                  ->orWhere('game_code', 'like', "%{$search}%");
            }))
            ->when($provider !== '', fn ($q) => $q->where('product_id', $provider))
            ->when($category !== '', fn ($q) => $q->where('category', $category))
            ->orderBy('product_id')
            ->orderBy('game_name')
            ->limit(60)
            ->get(['id', 'product_id', 'game_code', 'game_name', 'game_name_th', 'image_url', 'category']);

        return response()->json([
            'status' => 'success',
            'data'   => $rows->map(fn ($g) => [
                'key'      => $g->product_id . ':' . $g->game_code,   // รหัสที่ใช้เก็บ
                'provider' => $g->product_id,
                'code'     => $g->game_code,
                'name'     => $g->game_name_th ?: $g->game_name,
                'image'    => $g->image_url,
                'category' => $g->category,
            ]),
        ]);
    }

    /** ดึงข้อมูลเกมจากรหัสที่เก็บไว้ (ตอนเปิดฟอร์มแก้ไข) */
    public function gamesByKeys(Request $request): JsonResponse
    {
        $keys = (array) $request->input('keys', []);
        if (empty($keys)) {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        $pairs = collect($keys)->map(function ($k) {
            $p = explode(':', $k, 2);
            return count($p) === 2 ? ['provider' => $p[0], 'code' => $p[1]] : null;
        })->filter()->values();

        $rows = DB::table('games')
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as $p) {
                    $q->orWhere(fn ($s) => $s->where('product_id', $p['provider'])->where('game_code', $p['code']));
                }
            })
            ->limit(300)
            ->get(['product_id', 'game_code', 'game_name', 'game_name_th', 'image_url', 'category']);

        return response()->json([
            'status' => 'success',
            'data'   => $rows->map(fn ($g) => [
                'key'      => $g->product_id . ':' . $g->game_code,
                'provider' => $g->product_id,
                'code'     => $g->game_code,
                'name'     => $g->game_name_th ?: $g->game_name,
                'image'    => $g->image_url,
                'category' => $g->category,
            ]),
        ]);
    }
}