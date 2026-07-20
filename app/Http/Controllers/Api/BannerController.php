<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    // 🟢 สำหรับฝั่งลูกค้า (ดึงเฉพาะที่เปิดใช้งาน)
    public function index()
    {
        $banners = Banner::where('is_active', true)
                         ->orderBy('sort_order', 'asc')
                         ->get();

        return response()->json([
            'status' => 'success',
            'data' => $banners
        ]);
    }

    // 🔴 สำหรับฝั่ง Admin (ดึงทั้งหมด)
    public function adminIndex()
    {
        $banners = Banner::orderBy('sort_order', 'asc')->get();
        return response()->json(['status' => 'success', 'data' => $banners]);
    }

    // 🔴 สำหรับฝั่ง Admin (เพิ่มแบนเนอร์ใหม่)
    public function store(Request $request)
    {
        $request->validate(['image_url' => 'required|string']);
        
        $banner = Banner::create([
            'image_url' => $request->image_url,
            'is_active' => $request->is_active ?? true,
            'sort_order' => $request->sort_order ?? 0,
        ]);

        return response()->json(['status' => 'success', 'message' => 'เพิ่มแบนเนอร์สำเร็จ', 'data' => $banner]);
    }

    // 🔴 สำหรับฝั่ง Admin (ลบแบนเนอร์)
    public function destroy($id)
    {
        $banner = Banner::find($id);
        if ($banner) {
            $banner->delete();
            return response()->json(['status' => 'success', 'message' => 'ลบแบนเนอร์สำเร็จ']);
        }
        return response()->json(['status' => 'error', 'message' => 'ไม่พบแบนเนอร์'], 404);
    }
}