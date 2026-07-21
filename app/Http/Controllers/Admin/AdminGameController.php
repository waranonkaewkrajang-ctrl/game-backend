<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\AMBService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\ProductImage;

class AdminGameController extends Controller
{
    public function __construct(
        private AMBService $ambService,
    ) {}

    /**
     * ดึงรายการค่ายเกมจาก AMB
     */
    public function getProducts(): JsonResponse
    {
        $result = $this->ambService->getProducts();

        if (($result['code'] ?? 9999) !== 0) {
            return response()->json(['status' => 'error', 'message' => $result['message'] ?? 'ดึงข้อมูลไม่ได้'], 400);
        }

        return response()->json(['status' => 'success', 'data' => $result['data']]);
    }

    /**
     * Sync เกมจาก AMB เข้า database ของเรา
     */
    public function syncGames(Request $request): JsonResponse
    {
        $data = $request->validate([
            'productId' => 'required|string',
        ]);

        $result = $this->ambService->getGames($data['productId']);

        if (($result['code'] ?? 9999) !== 0) {
            return response()->json(['status' => 'error', 'message' => $result['message'] ?? 'ดึงเกมไม่ได้'], 400);
        }

        $games = $result['data']['games'] ?? [];
        $synced = 0;

        foreach ($games as $game) {
            Game::updateOrCreate(
                [
                    'product_id' => $data['productId'],
                    'game_code'  => $game['code'],
                ],
                [
                    'game_name'    => $game['name'] ?? $game['code'],
                    'game_name_th' => $game['locale']['th'] ?? $game['name'] ?? null,
                    'category'     => $game['category'] ?? null,
                    'type'         => $game['type'] ?? null,
                    'image_url'    => $game['img'] ?? null,
                    'rank'         => $game['rank'] ?? 0,
                ]
            );
            $synced++;
        }

        return response()->json([
            'status'  => 'success',
            'message' => "Sync สำเร็จ {$synced} เกม จากค่าย {$data['productId']}",
            'data'    => ['synced' => $synced, 'productId' => $data['productId']],
        ]);
    }

    /**
     * ดูเกมทั้งหมดใน database (พร้อม filter)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Game::query();

        if ($request->filled('productId')) {
            $query->where('product_id', $request->productId);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('game_name', 'like', "%{$search}%")
                  ->orWhere('game_name_th', 'like', "%{$search}%")
                  ->orWhere('game_code', 'like', "%{$search}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        $games = $query->orderBy('product_id')->orderBy('rank')->paginate(50);

        // นับสรุป
        $summary = [
            'total'    => Game::count(),
            'active'   => Game::where('is_active', true)->count(),
            'inactive' => Game::where('is_active', false)->count(),
            'products' => Game::select('product_id')
                ->selectRaw('count(*) as total')
                ->selectRaw('sum(case when is_active = 1 then 1 else 0 end) as active')
                ->groupBy('product_id')
                ->get(),
        ];

        return response()->json([
            'status'  => 'success',
            'data'    => $games,
            'summary' => $summary,
        ]);
    }

    /**
     * เปิด/ปิดเกม
     */
    public function toggleGame(Game $game): JsonResponse
    {
        $game->update(['is_active' => !$game->is_active]);

        return response()->json([
            'status'  => 'success',
            'message' => $game->is_active ? "เปิดเกม {$game->game_name} แล้ว" : "ปิดเกม {$game->game_name} แล้ว",
            'data'    => $game,
        ]);
    }

    /**
     * เปิด/ปิดทั้งค่าย
     */
    public function toggleProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'productId' => 'required|string',
            'is_active' => 'required|boolean',
        ]);

        $count = Game::where('product_id', $data['productId'])
            ->update(['is_active' => $data['is_active']]);

        return response()->json([
            'status'  => 'success',
            'message' => ($data['is_active'] ? 'เปิด' : 'ปิด') . "ทั้งค่าย {$data['productId']} ({$count} เกม)",
        ]);
    }

    /**
     * ดูเครดิต Agent
     */
    public function agentCredit(): JsonResponse
    {
        $result = $this->ambService->getAgentCredit();

        if (($result['code'] ?? 9999) !== 0) {
            return response()->json(['status' => 'error', 'message' => $result['message'] ?? 'ดึงข้อมูลไม่ได้'], 400);
        }

        return response()->json(['status' => 'success', 'data' => $result['data']]);
    }
    /**
     * ดึงรูปปกค่ายเกมทั้งหมด
     */
    public function getProductImages(): JsonResponse
    {
        $images = ProductImage::all()->keyBy('product_id');
        return response()->json(['status' => 'success', 'data' => $images]);
    }

    /**
     * อัปโหลด/เปลี่ยนรูปปกค่ายเกม
     */
    public function updateProductImage(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|string',
            'image'      => 'required|file|mimes:jpg,jpeg,png,gif,webp|max:2048',
        ]);

        $file = $request->file('image');
        $filename = 'provider_' . strtolower($request->product_id) . '.' . $file->getClientOriginalExtension();
        $file->move(public_path('uploads/providers'), $filename);
        $imageUrl = '/uploads/providers/' . $filename;

        $record = ProductImage::updateOrCreate(
            ['product_id' => $request->product_id],
            ['image_url'  => $imageUrl]
        );

        return response()->json([
            'status'  => 'success',
            'message' => "อัปเดตรูปค่าย {$request->product_id} แล้ว",
            'data'    => $record,
        ]);
    }
}
