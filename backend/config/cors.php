<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Mengambil dari .env (FRONTEND_URLS), jika kosong otomatis fallback ke localhost React
    'allowed_origins' => array_merge(
        array_filter(array_map('trim', explode(',', env('FRONTEND_URLS', '')))),
        [
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'http://localhost:3000',
            'http://127.0.0.1:3000',
        ]
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Wajib: tanpa ini JS (axios) tidak bisa membaca nama file unduhan dari
    // header Content-Disposition saat FE & API beda origin (mis. :5173 vs :8000).
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,
];