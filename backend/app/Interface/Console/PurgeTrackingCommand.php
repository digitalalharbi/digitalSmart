<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Tracking\Command\PurgeTrackingData;
use Illuminate\Console\Command;

final class PurgeTrackingCommand extends Command
{
    protected $signature = 'tracking:purge';

    protected $description = 'Roll raw tracking events past each project’s retention into daily counts, delete them, and anonymise old submissions';

    public function handle(PurgeTrackingData $purge): int
    {
        $r = $purge();
        $this->info(sprintf(
            'Rolled up %d rows, deleted %d events and %d sessions, anonymised %d submissions and %d dispatches.',
            $r['rolled_up'], $r['events_deleted'], $r['sessions_deleted'], $r['submissions_anonymised'], $r['dispatches_anonymised'],
        ));

        return self::SUCCESS;
    }
}
