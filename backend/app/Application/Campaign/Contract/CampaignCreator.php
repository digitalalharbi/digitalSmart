<?php

declare(strict_types=1);

namespace App\Application\Campaign\Contract;

use App\Application\Campaign\DTO\CreationRequest;
use App\Domain\Advertising\Launch\CreateOutcome;
use App\Domain\Advertising\Launch\ReadBack;

/**
 * Creates one account's campaign, ad group and ads, paused, and reads them back. Every implementation sends the platform's
 * non-delivering value at every level; one that cannot create says why instead of pretending.
 */
interface CampaignCreator
{
    public function create(CreationRequest $request): CreateOutcome;

    /** @param list<string> $adIds */
    public function readBack(CreationRequest $request, string $campaignId, string $adGroupId, array $adIds): ReadBack;

    /**
     * What a request with this reference created — the check before an unknown outcome is ever retried.
     *
     * `null` means only one thing: the platform was asked and holds nothing for this reference. An implementation that could not
     * ask (no adapter, a refused or timed-out lookup) returns an `unknown` outcome or throws; it must never return `null`, because
     * `null` is what allows a second create.
     */
    public function find(CreationRequest $request): ?CreateOutcome;
}
