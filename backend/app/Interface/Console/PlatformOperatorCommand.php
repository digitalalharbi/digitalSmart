<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Platform\Command\SetPlatformOperator;
use App\Application\Platform\Exception\PlatformRefused;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Identity\Email;
use Illuminate\Console\Command;

/**
 * Grant or withdraw platform operator access. Only here, on the server: there is no screen that makes somebody an operator,
 * so nobody signed in to the product can make themselves one.
 */
final class PlatformOperatorCommand extends Command
{
    protected $signature = 'platform:operator
        {email : The person\'s sign-in address}
        {--revoke : Withdraw instead of grant}
        {--by= : Who is doing this}
        {--reason= : Why}';

    protected $description = 'Grant or withdraw platform operator access';

    public function handle(SetPlatformOperator $set): int
    {
        try {
            $set(
                new Email($this->text($this->argument('email'))),
                ! (bool) $this->option('revoke'),
                $this->text($this->option('by')),
                $this->text($this->option('reason')),
            );
        } catch (PlatformRefused|ResourceNotFound $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info($this->option('revoke') ? 'Platform access withdrawn.' : 'Platform access granted.');

        return self::SUCCESS;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
