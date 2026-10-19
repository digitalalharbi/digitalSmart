<?php

declare(strict_types=1);

namespace App\Application\Advertising\Contract;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\CampaignVitalsRow;
use App\Application\Advertising\DTO\EntityControlRow;
use App\Domain\Advertising\AdEntityType;
use DateTimeImmutable;

/**
 * The facts every campaign row is built from, for one window.
 *
 * The read model returns **vitals**, not verdicts. Health is a judgement and it lives in
 * `CampaignHealthPolicy`, where it can be read and tested exhaustively; computing it a second time in SQL
 * so the database could sort by it would be two sets of rules free to disagree, and the disagreement
 * would show up as a row whose colour contradicts its own explanation.
 */
interface CampaignControlReadModel
{
    /**
     * @param  list<string>|null  $visibleProjectIds  Null is every project; `[]` is none, and stays distinct.
     * @return list<CampaignVitalsRow>
     */
    public function vitals(
        string $workspaceId,
        ?array $visibleProjectIds,
        CampaignControlFilter $filter,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $cap,
    ): array;

    /**
     * Spend per day for a set of campaigns, summed in SQL and kept per currency — two currencies on one day are
     * two figures, never one.
     *
     * @param  list<string>  $campaignIds
     * @return array<string, list<int|null>> currency => one value per day from `$from` to `$to`, null where nothing was reported
     */
    public function spendByDay(string $workspaceId, array $campaignIds, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Spend per day for each campaign over a window, oldest first, `null` for an unreported day.
     *
     * One statement for any number of campaigns.
     *
     * @param  list<string>  $campaignIds
     * @return array<string, list<int|null>>
     */
    public function trends(string $workspaceId, array $campaignIds, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * What a status request has to name: the platform's own ids for one campaign the reader may see.
     *
     * Null where the campaign does not exist or is not theirs — the two are the same answer, so a reference
     * cannot be used to learn that a campaign exists on a client they have no access to.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return array{name: string, provider: string, status: string, currency: string|null, external_campaign_id: string, external_account_id: string, client_id: string|null, project_id: string|null}|null
     */
    public function writeTarget(string $workspaceId, ?array $visibleProjectIds, string $campaignId): ?array;

    /**
     * Ad groups or ads under the campaigns the filter selects, each with the figures of **its own** metric rows over
     * the window — never its children's or its parent's. Ordered by spend, capped.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @param  list<string>  $adGroupIds  narrow to these ad groups (and, for ads, to the ads in them); [] = all
     * @param  list<string>  $adIds  narrow to these ads; [] = all
     * @return list<EntityControlRow>
     */
    public function entities(
        string $workspaceId,
        ?array $visibleProjectIds,
        CampaignControlFilter $filter,
        AdEntityType $level,
        array $adGroupIds,
        array $adIds,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $cap,
    ): array;

    /**
     * What a proposal about one ad group or ad has to name: its provider, account and own external id, its parent
     * campaign's, and its status — or null when it is not visible to the reader.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return array{name: string, provider: string, status: string, currency: string|null, budget_minor: int|null, budget_mode: string, campaign_id: string, campaign_name: string, external_campaign_id: string, external_entity_id: string, external_account_id: string, client_id: string|null, project_id: string|null}|null
     */
    public function entityWriteTarget(string $workspaceId, ?array $visibleProjectIds, AdEntityType $level, string $entityId): ?array;

    /**
     * How many campaigns match the filter before the cap is applied.
     *
     * Published beside a capped page so the interface can say "showing 500 of 2,140" rather than implying
     * the agency has 500 campaigns.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    public function count(
        string $workspaceId,
        ?array $visibleProjectIds,
        CampaignControlFilter $filter,
    ): int;
}
