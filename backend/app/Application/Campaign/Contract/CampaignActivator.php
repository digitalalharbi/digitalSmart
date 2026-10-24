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
}
