<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Popup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPopupController extends Controller
{
    public function index(): JsonResponse
    {
        $popups = Popup::orderBy('sort_order')->orderByDesc('created_at')->get();
        return response()->json(['status' => 'success', 'data' => $popups]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'image_url'   => 'nullable|string|max:500',
            'link_url'    => 'nullable|string|max:500',
            'link_text'   => 'nullable|string|max:100',
            'is_active'   => 'boolean',
            'show_once'   => 'boolean',
            'sort_order'  => 'integer',
            'start_at'    => 'nullable|date',
            'end_at'      => 'nullable|date',
        ]);

        $data['created_by'] = $request->user()->id;

        $popup = Popup::create($data);
        return response()->json(['status' => 'success', 'data' => $popup], 201);
    }

    public function update(Request $request, Popup $popup): JsonResponse
    {
        $data = $request->validate([
            'title'       => 'string|max:255',
            'description' => 'nullable|string',
            'image_url'   => 'nullable|string|max:500',
            'link_url'    => 'nullable|string|max:500',
            'link_text'   => 'nullable|string|max:100',
            'is_active'   => 'boolean',
            'show_once'   => 'boolean',
            'sort_order'  => 'integer',
            'start_at'    => 'nullable|date',
            'end_at'      => 'nullable|date',
        ]);

        $popup->update($data);
        return response()->json(['status' => 'success', 'data' => $popup->fresh()]);
    }

    public function destroy(Popup $popup): JsonResponse
    {
        $popup->delete();
        return response()->json(['status' => 'success', 'message' => 'ลบ popup สำเร็จ']);
    }

    /** เปิด/ปิด popup */
    public function toggle(Popup $popup): JsonResponse
    {
        $popup->update(['is_active' => !$popup->is_active]);
        return response()->json(['status' => 'success', 'data' => $popup->fresh()]);
    }
}