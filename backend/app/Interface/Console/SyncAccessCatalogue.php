<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Access\Command\SyncAccessDefinitions;
use Illuminate\Console\Command;

/**
 * Bring every workspace's system roles in line with the code.
 *
 * Needed because the permission catalogue lives in an enum and is seeded at
 * provision time. Adding a permission therefore reaches new workspaces
 * immediately and existing ones not at all — so a deploy that introduces one
 * silently leaves every current workspace unable to use the feature, with no
 * error to explain why.
 *
 * Safe to re-run, and converging rather than additive: a permission removed from
 * a role in code is removed here too, because a role that kept one would be a
 * quiet privilege escalation. Roles a workspace created for itself are untouched.
 */
final class SyncAccessCatalogue extends Command
{
    protected $signature = 'access:sync {--workspace= : Limit to one workspace id}';

    protected $description = 'Sync the permission catalogue and every workspace\'s system roles from code';

    public function handle(SyncAccessDefinitions $sync): int
    {
        $only = $this->option('workspace');
        $workspaces = $sync(is_string($only) && $only !== '' ? $only : null);

        $this->components->info('Permission catalogue synced.');

        if ($workspaces === []) {
            $this->components->warn('No workspaces found.');

            return self::SUCCESS;
        }

        foreach ($workspaces as $name) {
            $this->components->twoColumnDetail($name, 'system roles synced');
        }

        $this->components->info(sprintf(
            '%d workspace%s brought up to date.',
            count($workspaces),
            count($workspaces) === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
