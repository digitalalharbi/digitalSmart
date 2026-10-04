<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Strict by design. Only the first-party SPA origins listed in FRONTEND_URL
| may call the API, and credentials are allowed because Sanctum's SPA auth
| relies on cookies. Never widen `allowed_origins` to '*': with
| supports_credentials enabled a wildcard is rejected by browsers and would be
| a cross-site request forgery risk if it were not.
|
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_URL', 'http://127.0.0.1:5174')),
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'X-Request-Id',
        'Accept-Language',
    ],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 600,

    'supports_credentials' => true,

];
