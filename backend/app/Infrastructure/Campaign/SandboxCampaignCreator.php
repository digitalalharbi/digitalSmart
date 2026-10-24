<?php

declare(strict_types=1);

namespace App\Infrastructure\Campaign;

use App\Application\Campaign\DTO\CreationRequest;
use App\Domain\Advertising\Launch\CreateOutcome;
use App\Domain\Advertising\Launch\ReadBack;
use App\Domain\Advertising\MetricSource;
use Illuminate\Support\Facades\DB;

/**
 * Creation against a sandbox connection, so the whole path — queue, execution record, read-back, reconciliation, audit — runs
 * end to end in a demo and in the tests.
 *
 * The same rules as the sandbox writer: **every outcome is SANDBOX**, and it refuses to run for anything but a sandbox request.
 * Its ids are derived from the idempotency reference, so the same request always "creates" the same objects and `find` can tell
 * whether it already did. Its read-back reports the plan's paused state — which a real adapter must never do; a real one reads
 * the platform. That distinction is stated here so a live adapter is not written by copying this one.
 */
final readonly class SandboxCampaignCreator
{
    public function create(CreationRequest $request): CreateOutcome
    {
        if (! $request->sandbox) {
            return CreateOutcome::failed(MetricSource::Sandbox, 'not_sandbox', 'The sandbox creator refuses a connection with real credentials.');
        }

        [$campaign, $adGroup, $ads] = self::ids($request);

        return CreateOutcome::created(MetricSource::Sandbox, $campaign, $adGroup, $ads);
    }

    /** @param list<string> $adIds */
    public function readBack(CreationRequest $request, string $campaignId, string $adGroupId, array $adIds): ReadBack
    {
        $plan = $request->plan;
        [$expectedCampaign, $expectedGroup, $expectedAds] = self::ids($request);
        $mismatches = [];

        if ($campaignId !== $expectedCampaign || $adGroupId !== $expectedGroup || $adIds !== $expectedAds) {
            $mismatches[] = 'hierarchy';
        }

        return new ReadBack(true, [
            'campaign_id' => $campaignId,
            'status' => $plan->initialState,
            'budget_minor' => $plan->budgetMinor,
            'currency' => $plan->accountCurrency ?? $plan->currency,
            'starts_at' => $plan->startsAt->format(DATE_ATOM),
            'ends_at' => $plan->endsAt?->format(DATE_ATOM),
            'objective' => $plan->objective,
            'ad_group_id' => $adGroupId,
            'ad_ids' => $adIds,
            'source' => MetricSource::Sandbox->value,
        ], $mismatches);
    }

    public function find(CreationRequest $request): ?CreateOutcome
    {
        [$campaign, $adGroup, $ads] = self::ids($request);
        $exists = DB::table('campaigns')->where('workspace_id', $request->workspaceId)->where('external_id', $campaign)->exists();

        return $exists ? CreateOutcome::created(MetricSource::Sandbox, $campaign, $adGroup, $ads) : null;
    }

    /** @return array{0: string, 1: string, 2: list<string>} */
    private static function ids(CreationRequest $request): array
    {
        $key = strtoupper(substr($request->idempotencyReference, 0, 16));
        $ads = [];

        for ($i = 1; $i <= max(1, $request->plan->ads); $i++) {
            $ads[] = sprintf('SANDBOX-AD-%s-%d', $key, $i);
        }

        return ['SANDBOX-CAMPAIGN-'.$key, 'SANDBOX-ADGROUP-'.$key, $ads];
    }
}
