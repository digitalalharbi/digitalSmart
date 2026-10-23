<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Tracking\Contract\ConversionSender;
use App\Application\Tracking\Contract\ProviderDispatchStore;
use App\Application\Tracking\DTO\DispatchRequest;
use App\Domain\Integration\ConnectionStatus;
use App\Domain\Integration\Provider;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Tracking\Dispatch\SkipReason;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;

/**
 * Carry out one pending dispatch. A system action: it holds no person's permissions, and decides nothing the dispatcher did
 * not already decide — only whether there is a connection to send through, and what came back.
 *
 * @phpstan-type Result 'sent'|'skipped'|'failed'|'retry'|'unknown'|'final'
 */
final readonly class DispatchConversion
{
    public const int MAX_ATTEMPTS = 5;

    public function __construct(
        private ProviderDispatchStore $dispatches,
        private ProviderConnectionRepository $connections,
        private ConversionSender $sender,
    ) {}

    /** @return Result */
    public function __invoke(string $dispatchId): string
    {
        $d = $this->dispatches->find($dispatchId);

        /* Sent, failed, unknown and skipped are final here: only a person retries, with the same event id. */
        if ($d === null || $d['status'] !== 'pending') {
            return 'final';
        }

        $provider = Provider::tryFrom($d['provider']);
        $connection = $provider === null ? null : $this->connections->agencyConnection(new WorkspaceId($d['workspace_id']), $provider);

        $sandbox = $provider !== null && $connection !== null && $connection->credentials()->isSandbox($provider->requiredCredentialKeys());
        $usable = $connection !== null && ($connection->status() === ConnectionStatus::Connected || ($sandbox && $connection->status() === ConnectionStatus::Configured));

        if ($provider === null || ! $usable) {
            $this->dispatches->markSkipped($dispatchId, SkipReason::NotConnected);

            return 'skipped';
        }

        if (! $sandbox) {
            /* BLOCKED_EXTERNAL: a live sender needs the provider's approved app and a real account to verify against. */
            $this->dispatches->markSkipped($dispatchId, SkipReason::LiveTransportBlocked);

            return 'skipped';
        }

        $this->dispatches->attempt($dispatchId);
        $outcome = $this->sender->send(new DispatchRequest(
            $d['workspace_id'], $d['provider'], $d['provider_event'], $d['event_id'], $d['channel'], $d['click_ids'], new DateTimeImmutable($d['occurred_at']), true,
        ));

        switch ($outcome->kind) {
            case 'sent':
                $this->dispatches->markSent($dispatchId, 'SANDBOX', $outcome->providerRef, $outcome->received, $outcome->deduplicated);

                return 'sent';
            case 'unknown':
                $this->dispatches->markUnknown($dispatchId);

                return 'unknown';
            case 'transient':
                if ($d['attempts'] + 1 >= self::MAX_ATTEMPTS) {
                    $this->dispatches->markFailed($dispatchId, $outcome->category ?? 'transient');

                    return 'failed';
                }

                return 'retry';
            default:
                $this->dispatches->markFailed($dispatchId, $outcome->category ?? 'rejected');

                return 'failed';
        }
    }
}
