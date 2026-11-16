<?php

// Customer website (game-client) settings that the backend needs to mirror exactly.
return [
    // Public URL of the customer website — avatar images are served from there (/avatars/NN.webp).
    // The "site_url" setting (หลังบ้าน → ตั้งค่า) overrides this when it is filled in.
    'site_url' => env('CLIENT_SITE_URL'),

    'avatars' => [
        // must equal AVATAR_COUNT in game-client app/profile/utils/avatar.ts (images public/avatars/01..NN.webp)
        'count' => (int) env('CUSTOMER_AVATAR_COUNT', 18),
    ],

    'presence' => [
        // "online" = the member's login token called the API within this many minutes
        // (the website refreshes the wallet balance every 10 s while it is open)
        'online_minutes' => (int) env('CUSTOMER_ONLINE_MINUTES', 5),
        // presence:publish sends an event when the count changes, or at least this often
        'heartbeat_minutes' => (int) env('CUSTOMER_PRESENCE_HEARTBEAT_MINUTES', 10),
    ],
];
