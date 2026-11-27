<?php

declare(strict_types=1);

$root = env('APP_ENV') === 'production'
    ? '/var/private-documentation'
    : base_path('var/private-documentation');

return [
    'root' => $root,
    'site_directory' => 'site',
    'manifest' => 'manifest.json',
    'authorization_placeholder' => false,
    'mime_types' => [
        '.avif' => 'image/avif',
        '.css' => 'text/css; charset=utf-8',
        '.gif' => 'image/gif',
        '.html' => 'text/html; charset=utf-8',
        '.ico' => 'image/x-icon',
        '.jpeg' => 'image/jpeg',
        '.jpg' => 'image/jpeg',
        '.js' => 'text/javascript; charset=utf-8',
        '.json' => 'application/json; charset=utf-8',
        '.mjs' => 'text/javascript; charset=utf-8',
        '.png' => 'image/png',
        '.txt' => 'text/plain; charset=utf-8',
        '.webmanifest' => 'application/manifest+json; charset=utf-8',
        '.webp' => 'image/webp',
        '.woff' => 'font/woff',
        '.woff2' => 'font/woff2',
    ],
    'security_headers' => [
        'Cache-Control' => 'private, no-store',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ],
    'html_csp' => "default-src 'self'; base-uri 'none'; connect-src 'self'; form-action 'none'; frame-ancestors 'none'; object-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:",
];
