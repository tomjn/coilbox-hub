<?php

return [
    // The Supabase project behind the hub. The publishable key is public: the
    // hub hands it to the desktop app at /api/v1/auth. The service role key
    // bypasses row level security and never leaves the server.
    'supabase' => [
        'url' => rtrim((string) env('SUPABASE_URL', ''), '/'),
        'publishable_key' => (string) env('SUPABASE_PUBLISHABLE_KEY', ''),
        'service_role_key' => (string) env('SUPABASE_SERVICE_ROLE_KEY', ''),
        'timeout' => (int) env('SUPABASE_TIMEOUT', 10),
    ],

    // Where promoted pictures are published (the coilbox-assets repository on
    // GitHub Pages). Always ends in exactly one slash.
    'asset_cdn_base' => rtrim((string) env('ASSET_CDN_BASE', 'https://tomjn.github.io/coilbox-assets/'), '/').'/',

    // Hub mirrors. The secret key signs upload tokens and the catalogue. It
    // comes from bin/keygen.php in the mirror repository and is never committed.
    // The next key is set only while rotating keys. The window is the spec's
    // 5 minute start-by time and the reservation is its 24 hour upload limit,
    // both in seconds, from the time limits table.
    'mirrors' => [
        'secret_key' => (string) env('HUB_MIRROR_SECRET_KEY', ''),
        'next_secret_key' => (string) env('HUB_MIRROR_NEXT_SECRET_KEY', ''),
        'token_window' => (int) env('HUB_MIRROR_TOKEN_WINDOW', 300),
        'reservation' => (int) env('HUB_MIRROR_RESERVATION', 86400),
    ],

    // The daily promotion job in tomjn/coilbox-assets calls /api/v1/promotion
    // with a bearer token (#616). Only the token's SHA-256 hash is kept here.
    // `php artisan promotion:token` makes a token and its hash. The next hash
    // is set only while rotating, so the old and new tokens both work.
    'promotion' => [
        'token_hash' => strtolower(trim((string) env('PROMOTION_TOKEN_HASH', ''))),
        'next_token_hash' => strtolower(trim((string) env('PROMOTION_NEXT_TOKEN_HASH', ''))),
    ],

    // Which database holds the game catalogue. Leave unset in production, where
    // `data_move` holds it and `php artisan games:copy` switches it. Set to
    // `supabase` or `mariadb` to fix it, as the tests do.
    'games_store' => env('HUB_GAMES_STORE'),

    // The same for pictures and the staged picture bucket (#598), switched by
    // `php artisan pictures:copy`.
    'pictures_store' => env('HUB_PICTURES_STORE'),

    // The same for maps, map authors and map packs (#599), switched by
    // `php artisan maps:copy`.
    'maps_store' => env('HUB_MAPS_STORE'),

    // The same for the hub mirrors, their archives and the catalogue (#600),
    // switched by `php artisan mirrors:copy` once games and maps have moved.
    'mirrors_store' => env('HUB_MIRRORS_STORE'),

    // The same for published items, reports, profiles and moderator
    // capabilities (#601), switched by `php artisan items:copy` once maps
    // have moved.
    'items_store' => env('HUB_ITEMS_STORE'),

    // Where the browser sign in runs (#602): `supabase`, or `mariadb` once
    // `php artisan users:copy --switch` has moved it to the hub.
    'signin_store' => env('HUB_SIGNIN_STORE'),

    // The Coilbox desktop app's website.
    'coilbox_url' => 'https://coilbox.org/',
];
