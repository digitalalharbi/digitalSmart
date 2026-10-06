<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Platform settings
    |--------------------------------------------------------------------------
    |
    | Application-wide knobs that are neither framework config nor per-feature
    | settings. Feature flags and workspace-level policy arrive in Phase 1 and
    | live in the database, not here.
    |
    */

    'slow_query_threshold_ms' => (int) env('SLOW_QUERY_THRESHOLD_MS', 500),

    /*
    | Demo/sandbox data must always be distinguishable from client data.
    | Seeders tag every record they create with this label.
    */
    'demo_data_label' => 'DEMO',

    /*
    |--------------------------------------------------------------------------
    | Rate limits (requests per minute)
    |--------------------------------------------------------------------------
    |
    | Configurable so operators can tune them per environment, with secure
    | defaults. `auth` applies per IP *and* per e-mail address, so raising it
    | for a local end-to-end run does not weaken the per-account protection
    | that matters in production — keep the production value low.
    |
    */

    'rate_limits' => [
        'api_user' => (int) env('RATE_LIMIT_API_USER', 120),
        'api_guest' => (int) env('RATE_LIMIT_API_GUEST', 30),
        'health' => (int) env('RATE_LIMIT_HEALTH', 60),
        'auth' => (int) env('RATE_LIMIT_AUTH', 5),
        // A shop on a sale day delivers hundreds a minute; throttling real orders is the worse
        // failure, and a 429 only makes the provider redeliver.
        'webhooks' => (int) env('RATE_LIMIT_WEBHOOKS', 600),
    ],

];
