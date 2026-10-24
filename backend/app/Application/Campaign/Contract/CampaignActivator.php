<?php

declare(strict_types=1);

namespace App\Application\Campaign\Contract;

use App\Application\Campaign\DTO\CreationRequest;
use App\Domain\Advertising\Launch\ActivationOutcome;

/** Switch a created, verified campaign on, and read back that the platform says it is on. */
interface CampaignActivator
{
    /** @param list<string> $adIds */
    public function activate(CreationRequest $request, string $campaignId, string $adGroupId, array $adIds): ActivationOutcome;

    /**
     * What the platform says about the campaign now, changing nothing — the check before an ambiguous activation is settled.
     * `Active` only with the platform's own observed state; `Failed` only when it was asked and reports the campaign not on;
     * `Unknown` whenever it could not be asked.
     *
     * @param  list<string>  $adIds
     */
    public function read(CreationRequest $request, string $campaignId, string $adGroupId, array $adIds): ActivationOutcome;
}
