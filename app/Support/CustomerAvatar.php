<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The customer's avatar exactly as the customer website shows it.
 *
 * 1. the avatar the member picked on /profile (users.avatar, saved by the website)
 * 2. otherwise the website's default: getAvatarPath(username) in game-client app/profile/utils/avatar.ts
 *    — a 32-bit string hash of the username → /avatars/01.webp … NN.webp. defaultId() is a 1:1 port
 *    (same arithmetic on UTF-16 code units, same modulo), checked against the JS function in the tests.
 * Images are served by the customer website itself, so a changed image there is shown here too.
 */
final class CustomerAvatar
{
    /** @return list<string> "01" … "NN" */
    public static function ids(): array
    {
        $n = max(1, (int) config('customer.avatars.count', 18));

        return array_map(fn (int $i) => str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, $n));
    }

    public static function isValid(mixed $id): bool
    {
        return is_string($id) && in_array($id, self::ids(), true);
    }

    /** port of: hash = (hash << 5) - hash + charCodeAt(i); hash |= 0; id = abs(hash) % N + 1 */
    public static function defaultId(string $username): string
    {
        $h = 0;
        if ($username !== '') {
            foreach (unpack('n*', mb_convert_encoding($username, 'UTF-16BE', 'UTF-8')) as $unit) {
                $h = self::int32($h * 31 + $unit);
            }
        }

        return str_pad((string) ((abs($h) % count(self::ids())) + 1), 2, '0', STR_PAD_LEFT);
    }

    public static function siteUrl(): ?string
    {
        $url = '';
        try {
            $url = trim((string) Setting::getValue('site_url'));
        } catch (\Throwable) {
            // settings table unavailable → config only
        }
        if ($url === '') {
            $url = trim((string) config('customer.site_url'));
        }

        return $url !== '' ? rtrim($url, '/') : null;
    }

    /** @return array{avatar_id:string, avatar_selected:bool, avatar_url:?string} */
    public static function resolve(?string $selected, string $username, ?string $siteUrl = null): array
    {
        $isSelected = self::isValid($selected);
        $id = $isSelected ? $selected : self::defaultId($username);
        $siteUrl ??= self::siteUrl();

        return [
            'avatar_id'       => $id,
            'avatar_selected' => $isSelected,
            'avatar_url'      => $siteUrl ? "{$siteUrl}/avatars/{$id}.webp" : null,
        ];
    }

    private static function int32(int $v): int
    {
        $v &= 0xFFFFFFFF;

        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }
}
