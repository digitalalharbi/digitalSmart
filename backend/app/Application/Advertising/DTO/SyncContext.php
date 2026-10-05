<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\MetricSource;
use App\Domain\Integration\Provider;

/**
 * Everything a sync needs to know about where it is writing.
 *
 * Carries the source state explicitly rather than deriving it at write time:
 * whether a figure is sandbox or synced is decided once, where the connection's
 * credentials are known, and then travels with every row. Nothing downstream
 * gets to guess.
 */
final readonly class SyncContext
{
    public function __construct(
        public string $workspaceId,
        public string $externalAccountId,
        public string $externalAccountExternalId,
        public Provider $provider,
        public string $currency,
        public MetricSource $source,
        public ?string $accountTimezone = null,
        public ?string $providerConnectionId = null,
    ) {}
}
