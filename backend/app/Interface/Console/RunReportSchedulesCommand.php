<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Export\Command\RunDueSchedules;
use App\Interface\Queue\RunExportJob;
use Illuminate\Console\Command;

final class RunReportSchedulesCommand extends Command
{
    protected $signature = 'reports:run-schedules';

    protected $description = 'Queue the report exports that are due';

    public function handle(RunDueSchedules $run): int
    {
        $created = $run();

        foreach ($created as $export) {
            RunExportJob::dispatch($export->id, $export->workspaceId, $export->userId);
        }

        $this->components->info(sprintf('%d scheduled export(s) queued.', count($created)));

        return self::SUCCESS;
    }
}
