<?php

declare(strict_types=1);

namespace App\Interface\Queue;

use App\Application\Tracking\Command\DispatchConversion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One conversion, sent in the background.
 *
 * Retried only for a transient failure, with the same event id so a provider that de-duplicates counts it once. An unknown
 * outcome is final until a person retries it: the use case marks it, this job never re-sends it.
 */
final class DispatchProviderEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 60;

    public function __construct(private readonly string $dispatchId)
    {
        $this->onQueue('integrations');
    }

    public function handle(DispatchConversion $run): void
    {
        if ($run($this->dispatchId) === 'retry') {
            $this->release(60 * max(1, $this->attempts()));
        }
    }
}
