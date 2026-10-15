<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Export\Command\PruneExports;
use Illuminate\Console\Command;

final class PruneExportsCommand extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Delete export files past their retention';

    public function handle(PruneExports $prune): int
    {
        $this->components->info(sprintf('%d export file(s) removed.', $prune()));

        return self::SUCCESS;
    }
}
