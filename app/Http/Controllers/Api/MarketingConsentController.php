<?php

namespace App\Http\Controllers\Api;

use App\Domain\Events\DomainEvents;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * สมาชิกตั้งค่าความยินยอมรับข่าวสาร (SMS / LINE) หรือยกเลิกทั้งหมด
 * การเปลี่ยนแปลงถูกส่งต่อให้ CRM ผ่าน outbox (MemberEventsObserver) อัตโนมัติ
 */
class MarketingConsentController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => DomainEvents::consent($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sms'  => 'required|boolean',
            'line' => 'required|boolean',
        ]);
        $user = $request->user();
        $now = now();
        $user->forceFill([
            'marketing_sms_consent_at'  => $data['sms'] ? ($user->marketing_sms_consent_at ?? $now) : null,
            'marketing_line_consent_at' => $data['line'] ? ($user->marketing_line_consent_at ?? $now) : null,
            'marketing_opted_out_at'    => ($data['sms'] || $data['line']) ? null : ($user->marketing_opted_out_at ?? $now),
        ])->save();

        return response()->json(['status' => 'success', 'data' => DomainEvents::consent($user->fresh())]);
    }
}
