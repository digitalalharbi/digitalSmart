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

];
