<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * The permission catalogue and the recorded creative specifications are required in every
         * environment — the specifications describe platforms rather than clients, so they are product
         * knowledge rather than demo data. The demo workspace is not required, and refuses to run in
         * production.
         */
        $this->call([
            PermissionSeeder::class,
            CreativeSpecSeeder::class,
            DemoWorkspaceSeeder::class,
        ]);
    }
}
