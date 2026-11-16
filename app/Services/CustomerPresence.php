<?php

namespace App\Services;

use App\Models\User;
use App\Support\CustomerAvatar;
use Illuminate\Support\Facades\DB;

/**
 * Which members are on the website right now.
 * A member is online when their login token called the API in the last N minutes (Sanctum updates
 * personal_access_tokens.last_used_at on every request; the website refreshes the balance every 10 s while open).
 * Counted per member (DISTINCT tokenable_id) → several tabs/devices = one member. Login deletes older tokens.
 * An expired session stops calling the API → drops out after N minutes, never counted forever.
 */
class CustomerPresence
{
    public function minutes(): int
    {
        return max(1, (int) config('customer.presence.online_minutes', 5));
    }

    public function onlineCount(): int
    {
        return DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('last_used_at', '>=', now()->subMinutes($this->minutes()))
            ->distinct()
            ->count('tokenable_id');
    }

    /** most recently active members (one query for all rows, avatars resolved in PHP — no per-avatar queries) */
    public function onlineMembers(int $limit = 100): array
    {
        $site = CustomerAvatar::siteUrl();

        return DB::table('personal_access_tokens as t')
            ->join('users as u', 'u.id', '=', 't.tokenable_id')
            ->where('t.tokenable_type', User::class)
            ->where('t.last_used_at', '>=', now()->subMinutes($this->minutes()))
            ->groupBy('u.id', 'u.username', 'u.avatar')
            ->selectRaw('u.id, u.username, u.avatar, MAX(t.last_used_at) AS last_seen')
            ->orderByDesc('last_seen')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id'        => (int) $r->id,
                'username'  => $r->username,
                'last_seen' => $r->last_seen,
            ] + CustomerAvatar::resolve($r->avatar, (string) $r->username, $site))
            ->all();
    }
}
