<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The permission catalogue is required in every environment; the demo
        // workspace is not, and refuses to run in production.
        $this->call([
            PermissionSeeder::class,
            DemoWorkspaceSeeder::class,
        ]);
    }
}
