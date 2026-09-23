<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AdminActivityController extends Controller
{
    private const SLOTS = ['home_banner', 'icon_grid', 'home_card', 'float_button', 'popup', 'bottom_menu'];
    private const PAGES = ['home', 'lobby', 'wallet', 'promotions', 'rewards', 'spin-wheel', 'history', 'profile'];

    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => Activity::orderBy('sort_order')->orderByDesc('id')->get(),
            'meta'   => ['slots' => self::SLOTS, 'pages' => self::PAGES],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()?->id;

        $activity = Activity::create($data);
        $this->clearCache();

        return response()->json(['status' => 'success', 'message' => 'สร้างกิจกรรมสำเร็จ', 'data' => $activity], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $activity = Activity::findOrFail($id);
        $activity->update($this->validated($request, true));
        $this->clearCache();

        return response()->json(['status' => 'success', 'message' => 'บันทึกสำเร็จ', 'data' => $activity->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        Activity::findOrFail($id)->delete();
        $this->clearCache();

        return response()->json(['status' => 'success', 'message' => 'ลบกิจกรรมแล้ว']);
    }

    /** เปิด/ปิดกิจกรรมเร็วๆ */
    public function toggle(int $id): JsonResponse
    {
        $activity = Activity::findOrFail($id);
        $activity->update(['is_active' => !$activity->is_active]);
        $this->clearCache();

        return response()->json(['status' => 'success', 'data' => ['is_active' => $activity->is_active]]);
    }

    /** เรียงลำดับใหม่ (ลากสลับ) */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        foreach ($data['ids'] as $i => $id) {
            Activity::where('id', $id)->update(['sort_order' => $i]);
        }
        $this->clearCache();

        return response()->json(['status' => 'success', 'message' => 'เรียงลำดับแล้ว']);
    }

    /**
     * อัปโหลดภาพ → แปลงเป็น WebP อัตโนมัติ (ไฟล์เล็ก โหลดเร็ว)
     * รับ: png jpg jpeg webp gif svg
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png,webp,gif,svg|max:5120',
            'thumb' => 'nullable|boolean',
        ]);

        $file = $request->file('image');
        $ext  = strtolower($file->getClientOriginalExtension());
        $dir  = public_path('uploads/activities');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $base = 'act_' . time() . '_' . bin2hex(random_bytes(4));

        // SVG และ GIF เก็บตามเดิม (แปลงแล้วเสียคุณภาพ/เสียภาพเคลื่อนไหว)
        if (in_array($ext, ['svg', 'gif'], true)) {
            $file->move($dir, "$base.$ext");
            return $this->urlResponse("$base.$ext");
        }

        $tmp = $file->getRealPath();
        $out = "$dir/$base.webp";
        $maxW = $request->boolean('thumb') ? 320 : 1200;

        // แปลงเป็น WebP — ลองเครื่องมือที่เร็วที่สุดก่อน
        $done = false;
        if (@is_executable('/usr/bin/cwebp')) {
            @exec(sprintf('/usr/bin/cwebp -q 82 -resize %d 0 %s -o %s 2>&1', $maxW, escapeshellarg($tmp), escapeshellarg($out)), $o, $code);
            $done = ($code === 0 && file_exists($out));
        }
        if (!$done && @is_executable('/usr/bin/convert')) {
            @exec(sprintf('/usr/bin/convert %s -resize %dx\> -quality 82 %s 2>&1', escapeshellarg($tmp), $maxW, escapeshellarg($out)), $o2, $code2);
            $done = ($code2 === 0 && file_exists($out));
        }

        if (!$done) {
            $file->move($dir, "$base.$ext");   // แปลงไม่ได้ ใช้ไฟล์เดิม
            return $this->urlResponse("$base.$ext");
        }

        return $this->urlResponse("$base.webp");
    }

    private function urlResponse(string $filename): JsonResponse
    {
        $path = '/uploads/activities/' . $filename;
        return response()->json([
            'status' => 'success',
            'url'    => rtrim(config('app.url'), '/') . $path,
            'size'   => @filesize(public_path(ltrim($path, '/'))) ?: 0,
        ]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'type'        => "$r|string|in:link,football,lotto2,popup",
            'title'       => "$r|string|max:100",
            'subtitle'    => 'nullable|string|max:150',
            'image_url'   => 'nullable|string|max:255',
            'image_thumb' => 'nullable|string|max:255',
            'canvas_data' => 'nullable|array',
            'slots'       => "$r|array|min:1",
            'slots.*'     => 'string|in:' . implode(',', self::SLOTS),
            'pages'       => 'nullable|array',
            'pages.*'     => 'string|in:' . implode(',', self::PAGES),
            'link_url'    => 'nullable|string|max:255',
            'sort_order'  => 'nullable|integer|min:0|max:999',
            'badge'       => 'nullable|string|max:20',
            'badge_color' => 'nullable|string|max:20',
            'is_active'   => 'nullable|boolean',
            'start_at'    => 'nullable|date',
            'end_at'      => 'nullable|date|after:start_at',
            'audience'    => 'nullable|in:all,member,guest',
            'show_once'   => 'nullable|boolean',
            'config'      => 'nullable|array',
        ]);

        return $data;
    }

    private function clearCache(): void
    {
        Cache::forget('site_activities');
    }
}