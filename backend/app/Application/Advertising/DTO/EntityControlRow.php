<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\CampaignVitals;
use App\Domain\Advertising\EntityStatus;
use DateTimeImmutable;

/**
 * One campaign, ad group or ad, with the figures its **own** metric rows report over a window.
 *
 * What a rule judges. A campaign's row is the Campaigns screen's own row, re-shaped; an ad group's and an ad's come
 * from their own `daily_metrics` rows (`entity_type` = their level) — never the sum of the level below, and never the
 * level above, because each level reports the same money once and adding levels counts it two or three times.
 *
 * The result an ad group or an ad is judged on is its campaign's — the objective lives on the campaign — so a lead
 * campaign's ads are judged on leads and a reach campaign's on reach.
 */
final readonly class EntityControlRow
{
    /**
     * @param  array<string, int|string|float>  $healthReasonParams
     */
    public function __construct(
        public AdEntityType $level,
        public string $id,
        public string $name,
        public string $campaignId,
        public string $campaignName,
        public ?string $adGroupId,
        public ?string $adGroupName,
        public string $provider,
        public string $externalAccountId,
        public EntityStatus $status,
        public CampaignObjective $objective,
        public ?string $projectId,
        public ?string $clientId,
        public ?string $currency,
        /** A daily budget on this entity itself, where the platform keeps one here; null otherwise. */
        public ?int $budgetMinor,
        public ?int $spendMinor,
        public ?float $results,
        public ?float $costPerResultMinor,
        public ?int $revenueMinor,
        public ?float $roas,
        public int $reportedDays,
        public ?float $budgetUtilisation = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $endsAt = null,
        public ?int $impressions = null,
        public ?int $clicks = null,
        /** Only a campaign carries a week-on-week judgement ("cost per result rose"); a level below has none. */
        public string $healthReasonCode = 'CAMPAIGN_UNKNOWN',
        public array $healthReasonParams = [],
        /** An ad's creative, so a rule about fatigue can name what to refresh. */
        public ?string $creativeId = null,
        public ?string $clientName = null,
        public ?string $projectName = null,
    ) {}

    public static function fromCampaign(CampaignControlRow $row): self
    {
        return new self(
            level: AdEntityType::Campaign,
            id: $row->campaignId,
            name: $row->campaignName,
            campaignId: $row->campaignId,
            campaignName: $row->campaignName,
            adGroupId: null,
            adGroupName: null,
            provider: $row->provider,
            externalAccountId: $row->externalAccountId,
            status: $row->status,
            objective: $row->objective,
            projectId: $row->projectId,
            clientId: $row->clientId,
            currency: $row->currency,
            budgetMinor: $row->budgetMinor,
            spendMinor: $row->spendMinor,
            results: $row->results,
            costPerResultMinor: $row->costPerResultMinor,
            revenueMinor: $row->revenueMinor,
            roas: $row->roas,
            reportedDays: $row->reportedDays,
            budgetUtilisation: $row->budgetUtilisation,
            startedAt: $row->startedAt,
            endsAt: $row->endsAt,
            impressions: $row->impressions,
            clicks: $row->clicks,
            healthReasonCode: $row->healthReasonCode,
            healthReasonParams: $row->healthReasonParams,
            clientName: $row->clientName,
            projectName: $row->projectName,
        );
    }

    /**
     * An ad group's or an ad's row from its own sums. Cost per result and ROAS follow the campaign's objective, by the
     * same rules as the campaign's own figures ({@see CampaignVitals}).
     */
    public static function fromSums(
        AdEntityType $level,
        string $id,
        string $name,
        string $campaignId,
        string $campaignName,
        ?string $adGroupId,
        ?string $adGroupName,
        string $provider,
        string $externalAccountId,
        EntityStatus $status,
        CampaignObjective $objective,
        ?string $projectId,
        ?string $clientId,
        ?string $currency,
        ?int $budgetMinor,
        string $budgetMode,
        CampaignVitals $vitals,
        ?DateTimeImmutable $startedAt,
        ?DateTimeImmutable $endsAt,
        ?string $creativeId,
        ?string $clientName = null,
        ?string $projectName = null,
    ): self {
        $spend = $vitals->spendMinor;
        $utilisation = $budgetMode === 'daily' && $budgetMinor !== null && $budgetMinor > 0 && $spend !== null && $vitals->reportedDays > 0
            ? ($spend / $vitals->reportedDays) / $budgetMinor
            : null;

        return new self(
            level: $level,
            id: $id,
            name: $name,
            campaignId: $campaignId,
            campaignName: $campaignName,
            adGroupId: $adGroupId,
            adGroupName: $adGroupName,
            provider: $provider,
            externalAccountId: $externalAccountId,
            status: $status,
            objective: $objective,
            projectId: $projectId,
            clientId: $clientId,
            currency: $currency,
            budgetMinor: $budgetMode === 'daily' ? $budgetMinor : null,
            spendMinor: $spend,
            results: $vitals->results,
            costPerResultMinor: $vitals->costPerResult(),
            revenueMinor: $objective->measuresRevenue() ? $vitals->revenueMinor : null,
            roas: $vitals->roas(),
            reportedDays: $vitals->reportedDays,
            budgetUtilisation: $utilisation,
            startedAt: $startedAt,
            endsAt: $endsAt,
            impressions: $vitals->impressions,
            clicks: $vitals->clicks,
            creativeId: $creativeId,
            clientName: $clientName,
            projectName: $projectName,
        );
    }

    /** What the entity sits under, as a person reads it: the campaign, or "campaign › ad group" for an ad. */
    public function parentLabel(): ?string
    {
        return match ($this->level) {
            AdEntityType::AdGroup => $this->campaignName,
            AdEntityType::Ad => $this->adGroupName === null ? $this->campaignName : $this->campaignName.' › '.$this->adGroupName,
            default => null,
        };
    }
}
