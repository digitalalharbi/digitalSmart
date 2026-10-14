<?php

declare(strict_types=1);

/*
 * The size of the synthetic agency `AgencyScaleSeeder` builds.
 *
 * Config rather than reading the environment inside the seeder, so a test can seed a smaller but
 * identically *shaped* agency with `config([...])` and the scale-invariance assertions can run twice at
 * two sizes inside one test process.
 *
 * The defaults are the minimum representative dataset the integration constitution names: 100 clients,
 * 100+ projects, 300 external accounts, 2,000 campaigns, and a daily fact table large enough that an
 * unindexed scan shows up.
 */
return [
    'clients' => (int) env('AGENCY_SCALE_CLIENTS', 100),
    'projects_per_client' => (int) env('AGENCY_SCALE_PROJECTS_PER_CLIENT', 2),
    'accounts' => (int) env('AGENCY_SCALE_ACCOUNTS', 300),
    'campaigns' => (int) env('AGENCY_SCALE_CAMPAIGNS', 2000),
    'days' => (int) env('AGENCY_SCALE_DAYS', 90),
];
