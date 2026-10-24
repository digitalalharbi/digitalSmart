<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Access\Command\RaiseAccessRequestAlerts;
use Illuminate\Console\Command;

final class RaiseAccessRequestAlertsCommand extends Command
{
    protected $signature = 'access-requests:alerts';

    protected $description = 'Tell the people who answer requests when one is close to, or past, its response time, or an invitation is unaccepted';

    public function handle(RaiseAccessRequestAlerts $raise): int
    {
        $r = $raise();
        $this->info(sprintf('%d approaching, %d overdue, %d invitations.', $r['approaching'], $r['overdue'], $r['invitations']));

        return self::SUCCESS;
    }
}
