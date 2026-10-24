<?php

declare(strict_types=1);

namespace App\Application\Campaign\Contract;

use App\Application\Campaign\DTO\ExecutionRecord;
use App\Domain\Advertising\Launch\ProviderCreatePlan;
use App\Domain\Advertising\MetricSource;

/**
 * Puts a verified creation into the campaign, ad group and ad tables the rest of the product already reads — no second campaign
 * model — assigned to the plan's project, paused, and labelled with where it came from.
 */
interface CampaignReconciler
{
    public function reconcile(ExecutionRecord $execution, ProviderCreatePlan $plan, string $name, MetricSource $source): void;
}
