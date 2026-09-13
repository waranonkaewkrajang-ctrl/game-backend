<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Popup;
use Illuminate\Http\JsonResponse;

class PopupController extends Controller
{
    public function index(): JsonResponse
    {
        $popups = Popup::active()
            ->orderBy('sort_order')
            ->get(['id', 'title', 'description', 'image_url', 'link_url', 'link_text', 'show_once']);

        return response()->json(['status' => 'success', 'data' => $popups]);
    }
}