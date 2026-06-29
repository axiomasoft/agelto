<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Gemini API Key
    |--------------------------------------------------------------------------
    |
    | Here you may specify your Gemini API Key and organization. This will be
    | used to authenticate with the Gemini API - you can find your API key
    | on Google AI Studio, at https://aistudio.google.com/app/apikey.
    */

    'api_key' => env('GEMINI_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Proxy
    |--------------------------------------------------------------------------
    |
    | Прокси для исходящих запросов к Gemini (например, при региональных
    | ограничениях). Пусто — без прокси.
    */

    'proxy' => env('GEMINI_PROXY'),

    /*
    |--------------------------------------------------------------------------
    | Gemini Base URL
    |--------------------------------------------------------------------------
    |
    | If you need a specific base URL for the Gemini API, you can provide it here.
    | Otherwise, leave empty to use the default value.
    */
    'base_url' => env('GEMINI_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout may be used to specify the maximum number of seconds to wait
    | for a response. By default, the client will time out after 30 seconds.
    */

    'request_timeout' => env('GEMINI_REQUEST_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Rate Limit (анти-429)
    |--------------------------------------------------------------------------
    |
    | До 10 параллельных воркеров analysis-очередей бьют один ключ через один
    | прокси — без троттлинга это ловит 429 пачкой. Ограничиваем число запросов
    | генерации на окно через Redis::throttle. max=0 — троттлинг выключен.
    */

    'rate_limit' => [
        'max' => (int) env('GEMINI_RATE_LIMIT_MAX', 60),
        'every_seconds' => (int) env('GEMINI_RATE_LIMIT_EVERY', 60),
        'block_seconds' => (int) env('GEMINI_RATE_LIMIT_BLOCK', 30),
    ],
];
