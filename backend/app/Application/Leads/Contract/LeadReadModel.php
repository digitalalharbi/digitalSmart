<?php

declare(strict_types=1);

namespace App\Application\Leads\Contract;

use App\Application\Leads\DTO\LeadBreakdownRow;
use App\Application\Leads\DTO\LeadFilter;
use App\Application\Leads\DTO\LeadResponseTime;
use App\Application\Leads\DTO\LeadSourceRow;
use App\Domain\Leads\LeadFunnel;
use DateTimeImmutable;

/**
 * Counting leads.
 *
 * Separate from the repository because the questions are different: one hands back records to work on, the
 * other hands back counts to report. Keeping them apart stops a reporting query loading a thousand leads
 * into memory to count them, which is how a Lead Center page comes to take four seconds.
 */
interface LeadReadModel
{
    /** @param list<string>|null $visibleProjectIds */
    public function funnel(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        ?LeadFilter $filter = null,
    ): LeadFunnel;

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return list<LeadSourceRow>
     */
    public function bySource(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        ?LeadFilter $filter = null,
    ): array;

    /**
     * Leads by campaign, with the unattributed ones in their own row.
     *
     * Only evidence that can name a campaign produces a named row — the same rule the revenue breakdown
     * uses, because a bare `utm_source` names a channel and splitting leads across a client's campaigns on
     * that basis would be inventing the split.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<LeadBreakdownRow>
     */
    public function byCampaign(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        ?LeadFilter $filter = null,
    ): array;

    /**
     * How fast the period's leads were first answered.
     *
     * Leads with no recorded first contact are counted as unanswered and kept out of the median and the buckets.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    public function responseTime(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        ?LeadFilter $filter = null,
    ): LeadResponseTime;
}
