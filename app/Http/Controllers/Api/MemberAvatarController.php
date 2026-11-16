<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CustomerAvatar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The avatar a member picks on the website (/profile). Before this it lived only in that browser's
 * localStorage — now it is saved here so every device and the admin show the same picture.
 * null = back to the website's default avatar for the username.
 */
class MemberAvatarController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'avatar' => ['present', 'nullable', 'string', Rule::in(CustomerAvatar::ids())],
        ]);
        // only this column (the request's user model may carry computed attributes, e.g. referral_count from /auth/me)
        $user = $request->user();
        $user->newQuery()->whereKey($user->getKey())->update(['avatar' => $data['avatar']]);
        $user->setAttribute('avatar', $data['avatar'])->syncOriginalAttribute('avatar');

        return response()->json([
            'status' => 'success',
            'data'   => ['avatar' => $data['avatar']],
        ]);
    }
}
