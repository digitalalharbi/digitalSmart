<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Reporting\Subscription\SendDueReportEmails;
use Illuminate\Console\Command;

/** Email each due recipient a link to their report — once per period, and only through a configured provider. */
final class SendReportEmailsCommand extends Command
{
    protected $signature = 'reports:email-due';

    protected $description = 'Email due report links to their recipients';

    public function handle(SendDueReportEmails $send): int
    {
        $tally = $send();

        $this->components->info(sprintf(
            '%d period(s) claimed: %d accepted by the provider, %d failed, %d awaiting credentials, %d skipped.',
            $tally['claimed'], $tally['accepted'], $tally['failed'], $tally['awaiting_credentials'], $tally['skipped'],
        ));

        return self::SUCCESS;
    }
}
