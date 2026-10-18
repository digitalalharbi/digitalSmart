<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Domain\Attention\DigestKind;
use Database\Seeders\DemoAgencySeeder;
use Database\Seeders\DemoWorkspaceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One command to a populated agency: the demo workspace and its people, then about sixty clients around them.
 *
 * Everything it writes is SANDBOX and flagged as demo. It refuses to run in production, and re-running it
 * replaces what it created and nothing else.
 */
final class DemoAgencyCommand extends Command
{
    protected $signature = 'demo:agency {--fresh : Wipe this local database and rebuild everything from scratch}';

    protected $description = 'Prepare a realistic, populated, SANDBOX agency for exploring the product';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->components->error('Refusing to build demo data in production.');

            return self::FAILURE;
        }

        if ($this->option('fresh') && ! app()->environment(['local', 'testing'])) {
            /* `--fresh` drops every table. Only a local or test database may be asked to. */
            $this->components->error('--fresh wipes the database and is only allowed in the local or testing environment.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->components->warn('Rebuilding this local database from scratch.');
            $this->call('migrate:fresh', ['--force' => true]);
        }

        if (DB::table('workspaces')->where('slug', 'demo-smart-code')->exists()) {
            $this->components->info('The demo workspace is already there; refreshing the agency around it.');
        } else {
            $this->components->info('Preparing the demo workspace…');
            $this->call('db:seed', ['--class' => DemoWorkspaceSeeder::class, '--force' => true]);
        }

        $this->components->info('Building the agency around it…');
        $this->call('db:seed', ['--class' => DemoAgencySeeder::class, '--force' => true]);

        /* The summaries each person would have received this morning, so the notification centre is not empty. */
        $workspace = DB::table('workspaces')->where('slug', 'demo-smart-code')->value('id');

        /* Rebuilt every run, so last run's bells are cleared first rather than stacked beneath the new ones. */
        DB::table('notifications')->where('workspace_id', $workspace)->where('kind', 'digest_ready')->delete();
        DB::table('digests')->where('workspace_id', $workspace)->delete();

        foreach (DigestKind::values() as $kind) {
            $this->callSilently('digests:build', ['kind' => $kind, '--workspace' => $workspace]);
        }

        $this->newLine();
        $this->table(['Role', 'Email', 'Password'], [
            ['Executive / owner', 'owner@demo.digitalsmart.test', 'password'],
            ['Administrator', 'admin@demo.digitalsmart.test', 'password'],
            ['Project manager', 'manager@demo.digitalsmart.test', 'password'],
            ['Media buyer', 'buyer@demo.digitalsmart.test', 'password'],
            ['Finance', 'finance@demo.digitalsmart.test', 'password'],
            ['Creative', 'creative@demo.digitalsmart.test', 'password'],
            ['Client', 'client@demo.digitalsmart.test', 'password'],
        ]);

        $this->components->warn('Every figure is a SANDBOX fixture. Nothing here is live provider data.');

        return self::SUCCESS;
    }
}
