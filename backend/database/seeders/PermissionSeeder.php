<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Infrastructure\Workspace\WorkspaceProvisioner;
use Illuminate\Database\Seeder;

/**
 * The permission catalogue is code, not data: it is derived from the
 * Permission enum so a permission can never exist in the database without an
 * enforcement point in the application.
 */
final class PermissionSeeder extends Seeder
{
    public function __construct(private readonly WorkspaceProvisioner $provisioner) {}

    public function run(): void
    {
        $this->provisioner->syncPermissionCatalogue();
    }
}
