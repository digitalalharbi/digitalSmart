<?php

declare(strict_types=1);

namespace App\Interface\Queue;

use App\Application\Commerce\Command\ProcessStoreWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Does the work a webhook asked for, away from the request.
 *
 * The provider has already been answered, so this can take as long as it needs and fail without
 * causing a redelivery. That is the whole point of the split: a handler that read an order from the
 * API inside the request would turn one slow call into a timeout, a timeout into a redelivery, and a
 * redelivery into a queue of duplicates.
 *
 * Retries are backed off rather than immediate. A failure here is usually the provider being
 * unreachable, and hammering it is how a workspace gets rate-limited out of its own data.
 */
final class ProcessStoreWebhookJob implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(private readonly string $eventId) {}

    public function handle(ProcessStoreWebhook $process): void
    {
        $process($this->eventId);
    }

    /** Record the failure against the event, so the journal answers "why is this order missing?". */
    public function failed(Throwable $exception): void
    {
        app(ProcessStoreWebhook::class)->recordFailure($this->eventId, $exception->getMessage());
    }
}
