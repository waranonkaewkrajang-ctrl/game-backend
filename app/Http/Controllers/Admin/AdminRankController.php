<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * อัปโหลดรูปแรงค์ — เก็บในเซิร์ฟเวอร์เรา ไม่พึ่งเว็บนอก
 */
class AdminRankController extends Controller
{
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png,webp,gif,svg|max:3072',
        ]);

        $file = $request->file('image');
        $ext  = strtolower($file->getClientOriginalExtension());
        $dir  = public_path('uploads/ranks');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $base = 'rank_' . time() . '_' . bin2hex(random_bytes(4));

        // SVG/GIF เก็บตามเดิม — แปลงแล้วเสียคุณภาพหรือภาพเคลื่อนไหว
        if (in_array($ext, ['svg', 'gif'], true)) {
            $file->move($dir, "$base.$ext");
            return $this->urlResponse("$base.$ext");
        }

        $tmp = $file->getRealPath();
        $out = "$dir/$base.webp";

        // แปลงเป็น WebP + ย่อเหลือ 256px (ไอคอนไม่ต้องใหญ่)
        $done = false;
        if (@is_executable('/usr/bin/cwebp')) {
            @exec(sprintf('/usr/bin/cwebp -q 88 -resize 256 0 %s -o %s 2>&1', escapeshellarg($tmp), escapeshellarg($out)), $o, $code);
            $done = ($code === 0 && file_exists($out));
        }
        if (!$done && @is_executable('/usr/bin/convert')) {
            @exec(sprintf('/usr/bin/convert %s -resize 256x256\> -quality 88 %s 2>&1', escapeshellarg($tmp), escapeshellarg($out)), $o2, $code2);
            $done = ($code2 === 0 && file_exists($out));
        }

        if (!$done) {
            $file->move($dir, "$base.$ext");
            return $this->urlResponse("$base.$ext");
        }

        return $this->urlResponse("$base.webp");
    }

    private function urlResponse(string $filename): JsonResponse
    {
        $path = '/uploads/ranks/' . $filename;
        return response()->json([
            'status' => 'success',
            'url'    => rtrim(config('app.url'), '/') . $path,
            'size'   => @filesize(public_path(ltrim($path, '/'))) ?: 0,
        ]);
    }
}