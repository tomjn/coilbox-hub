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

    // The Coilbox desktop app's website.
    'coilbox_url' => 'https://coilbox.org/',
];
