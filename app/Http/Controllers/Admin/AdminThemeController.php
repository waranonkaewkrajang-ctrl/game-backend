<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ThemeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminThemeController extends Controller
{
    public function __construct(private ThemeService $theme) {}

    // สำหรับหน้าเว็บลูกค้า (ไม่ต้อง login)
    public function publicShow(): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->theme->get()]);
    }

    // สำหรับหน้า admin
    public function show(): JsonResponse
    {
        return response()->json([
            'status'   => 'success',
            'data'     => $this->theme->get(),
            'defaults' => ThemeService::DEFAULTS,
            'fonts'    => ThemeService::FONTS,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $color = ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'];

        $data = $request->validate([
            'primary'       => $color,
            'primary_light' => $color,
            'accent'        => $color,
            'accent_dark'   => $color,
            'success'       => $color,
            'danger'        => $color,
            'bg'            => $color,
            'surface'       => $color,
            'text'          => $color,
            'text_muted'    => $color,
            'radius'        => 'nullable|integer|min:0|max:32',
            'btn_depth'     => 'nullable|integer|min:0|max:12',
            'glow'          => 'nullable|integer|min:0|max:100',
            'font'          => ['nullable', 'string', \Illuminate\Validation\Rule::in(ThemeService::FONTS)],
            'font_scale'    => 'nullable|integer|min:85|max:125',
        ]);

        $data = array_filter($data, fn ($v) => $v !== null);

        return response()->json([
            'status'  => 'success',
            'message' => 'บันทึกธีมสำเร็จ',
            'data'    => $this->theme->save(array_merge($this->theme->get(), $data)),
        ]);
    }
}