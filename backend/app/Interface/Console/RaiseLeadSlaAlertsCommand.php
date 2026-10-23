<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Leads\Command\RaiseLeadSlaAlerts;
use Illuminate\Console\Command;

final class RaiseLeadSlaAlertsCommand extends Command
{
    protected $signature = 'leads:sla-alerts';

    protected $description = 'Tell the right people when a lead is close to, or past, the response time its project promised';

    public function handle(RaiseLeadSlaAlerts $raise): int
    {
        $r = $raise();
        $this->info(sprintf('%d approaching, %d overdue.', $r['approaching'], $r['overdue']));

        return self::SUCCESS;
    }
}
