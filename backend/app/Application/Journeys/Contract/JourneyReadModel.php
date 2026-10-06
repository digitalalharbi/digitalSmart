<?php

declare(strict_types=1);

namespace App\Application\Journeys\Contract;

use App\Domain\Journeys\Journey;
use App\Domain\Shared\ValueObject\ContactPoint;

/**
 * Assembling what one person did.
 *
 * Reads rather than stores. An identity graph written to a table is a graph that goes stale the moment the next
 * order lands and that nobody dares rebuild; resolving on read means the answer is always made of the records a
 * person can see for themselves.
 *
 * **What this can and cannot assemble.** It joins the conversions — enquiries and orders — on normalised contact
 * details. It does not and cannot assemble the marketing touches before them: GA4's Data API returns aggregated
 * reports rather than per-user event streams, and the documented route to event-level data is a BigQuery export
 * this product does not read. So a "journey" here is a person's history of conversions, and
 * `docs/JOURNEYS.md` says that plainly rather than drawing a funnel from one data point.
 */
interface JourneyReadModel
{
    /**
     * Everything recorded for the person a lead or an order belongs to.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    public function forContact(
        string $workspaceId,
        ?array $visibleProjectIds,
        string $clientId,
        ContactPoint $contact,
    ): Journey;

    /**
     * The person a lead belongs to, by the lead's own id.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    public function forLead(string $workspaceId, ?array $visibleProjectIds, string $leadId): ?Journey;
}
