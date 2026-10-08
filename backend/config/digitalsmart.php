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
        /*
         * Shared report links. Twenty a minute per address: a recipient opens one a handful of times, and anybody
         * needing more is not reading a report. The endpoint is unauthenticated, so this is the only limit on it.
         */
        'shared_reports' => (int) env('RATE_LIMIT_SHARED_REPORTS', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | The assistant's language model
    |--------------------------------------------------------------------------
    |
    | BLOCKED_EXTERNAL on an API key, which is a production secret this
    | repository cannot hold. With no key the assistant still works: every
    | finding and every figure is computed by the product, and the narrative is
    | composed from them without a model. The key buys better prose and nothing
    | else, which is deliberate — a language model must not be a dependency of
    | reading your own figures.
    |
    | `max_tokens` is small on purpose. The task is to restate a brief in a few
    | paragraphs, and a generous ceiling would only pay for a model that had
    | decided to write an essay.
    |
    */

    'intelligence' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 1024),
        /*
         * Short. The findings are already on screen by the time this is called in anger, so a model that takes
         * longer than this has already cost the reader more than its paragraph is worth.
         */
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 20),
    ],

];
