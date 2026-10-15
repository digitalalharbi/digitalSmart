<?php

declare(strict_types=1);

namespace App\Interface\Console;

use Database\Seeders\DemoAgencySeeder;
use Database\Seeders\DemoWorkspaceSeeder;
use Illuminate\Console\Command;

/**
 * One command to a populated agency: the demo workspace and its people, then about sixty clients around them.
 *
 * Everything it writes is SANDBOX and flagged as demo. It refuses to run in production, and re-running it
 * replaces what it created and nothing else.
 */
final class DemoAgencyCommand extends Command
{
    protected $signature = 'demo:agency {--fresh : Rebuild the base demo workspace from scratch first}';

    protected $description = 'Prepare a realistic, populated, SANDBOX agency for exploring the product';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->components->error('Refusing to build demo data in production.');

            return self::FAILURE;
        }

        $this->components->info('Preparing the demo workspace…');
        $this->call('db:seed', ['--class' => DemoWorkspaceSeeder::class, '--force' => true]);

        $this->components->info('Building the agency around it…');
        $this->call('db:seed', ['--class' => DemoAgencySeeder::class, '--force' => true]);

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
