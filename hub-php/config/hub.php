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

    // The Coilbox desktop app's website.
    'coilbox_url' => 'https://coilbox.org/',
];
