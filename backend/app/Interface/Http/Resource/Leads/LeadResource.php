<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Leads;

use App\Application\Leads\DTO\LeadBreakdownRow;
use App\Application\Leads\DTO\LeadCentreView;
use App\Application\Leads\DTO\LeadDuplicateRow;
use App\Application\Leads\DTO\LeadSourceRow;
use App\Application\Leads\DTO\LeadView;

final readonly class LeadResource
{
    /**
     * One lead.
     *
     * Contact details are included because the people working a pipeline need them to work it. They are
     * **never** part of a client-portal response: they belong to the agency's client's prospect, and the
     * portal's own resources are built separately for exactly this reason.
     *
     * @return array<string, mixed>
     */
    public static function lead(LeadView $lead): array
    {
        return [
            'id' => $lead->id,
            'client_id' => $lead->clientId,
            'client_name' => $lead->clientName,
            'project_id' => $lead->projectId,
            'source_kind' => $lead->sourceKind->value,
            'source_kind_label' => $lead->sourceKind->label(),
            'source_detail' => $lead->sourceDetail,
            /*
             * Whether this lead was measured or entered by hand. Published so a figure built from it can say
             * so: a hand-entered lead is a real lead and must never be reported as a campaign result.
             */
            'is_measured' => $lead->sourceKind->isMeasured(),
            'stage' => $lead->stage->value,
            'stage_label' => $lead->stage->label(),
            'is_closed' => $lead->stage->isClosed(),
            'disqualified_reason' => $lead->disqualifiedReason,
            'owner_id' => $lead->ownerId,
            'owner_name' => $lead->ownerName,
            'name' => $lead->name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'message' => $lead->message,
            'value_minor' => $lead->valueMinor,
            'currency' => $lead->currency,
            /*
             * The same evidence vocabulary the orders use. One standard of proof across leads and revenue, so
             * a cross-surface report cannot compare two different ones.
             */
            'evidence' => $lead->evidence->value,
            'evidence_label' => $lead->evidence->label(),
            'confidence' => $lead->evidence->confidence(),
            'utm_source' => $lead->utmSource,
            'utm_campaign' => $lead->utmCampaign,
            'received_at' => $lead->receivedAt->format(DATE_ATOM),
            'first_contacted_at' => $lead->firstContactedAt?->format(DATE_ATOM),
            'qualified_at' => $lead->qualifiedAt?->format(DATE_ATOM),
            'closed_at' => $lead->closedAt?->format(DATE_ATOM),
            /*
             * The figure that decides whether a lead was worth buying. A lead contacted within an hour
             * converts at a different rate from one contacted next week, and without this an agency cannot
             * tell a bad campaign from a slow sales team.
             */
            'hours_to_first_contact' => $lead->hoursToFirstContact(),
            /*
             * The merge, where there was one. `merged_into_id` on a lead means this record is not counted
             * anywhere and the person's history continues elsewhere — a surface that has it in hand should send
             * the reader there rather than render a lead that looks live.
             */
            'merged_into_id' => $lead->mergedIntoId,
            'merged_at' => $lead->mergedAt?->format(DATE_ATOM),
            /*
             * How many enquiries are on this record. Published so a history longer than one arrival explains
             * itself, and so a reader quoting "one lead" knows it was two.
             */
            'absorbed_count' => $lead->absorbedCount,
        ];
    }

    /**
     * A lead that may be the same person as another.
     *
     * `matched_on_label` travels with every row, because a merge cannot be undone and a suggestion with no
     * evidence beside it is one somebody accepts without looking. Nothing here matches on a name.
     *
     * @return array<string, mixed>
     */
    public static function duplicate(LeadDuplicateRow $row): array
    {
        return [
            'id' => $row->id,
            'source_kind' => $row->sourceKind->value,
            'source_kind_label' => $row->sourceKind->label(),
            'source_detail' => $row->sourceDetail,
            'stage' => $row->stage->value,
            'stage_label' => $row->stage->label(),
            'name' => $row->name,
            'email' => $row->email,
            'phone' => $row->phone,
            'received_at' => $row->receivedAt->format(DATE_ATOM),
            'matched_on' => $row->matchedOn,
            'matched_on_label' => $row->matchedOnLabel,
        ];
    }

    /** @return array<string, mixed> */
    public static function centre(LeadCentreView $view): array
    {
        $funnel = $view->funnel;

        return [
            'window_days' => $view->windowDays,
            'funnel' => [
                'total' => $funnel->total,
                'contacted' => $funnel->contacted,
                'reached' => $funnel->reached,
                'qualified' => $funnel->qualified,
                'disqualified' => $funnel->disqualified,
                'won' => $funnel->won,
                'lost' => $funnel->lost,
                'open' => $funnel->open,
                'closed' => $funnel->closed(),
                'won_value_minor' => $funnel->wonValueMinor,
                'open_value_minor' => $funnel->openValueMinor,
                /*
                 * The currency those amounts are in, published beside them because an amount without its
                 * currency is not a figure. Null — along with null amounts — when the window's valued leads
                 * came in more than one currency, which `currency_count` then states so the interface can
                 * explain the gap rather than show a dash nobody can account for.
                 */
                'currency' => $funnel->currency,
                'currency_count' => $funnel->currencyCount,
                'is_value_reportable' => $funnel->isValueReportable(),
                /*
                 * Every rate is null rather than zero when its denominator is empty, and each one's
                 * denominator is stated above so a reader can check it. The win rate divides by *closed*
                 * leads, because an open lead is not a loss — the arithmetic that has sunk more agency
                 * reports than any other in this area.
                 */
                'win_rate' => $funnel->winRate(),
                'qualification_rate' => $funnel->qualificationRate(),
                'contact_rate' => $funnel->contactRate(),
                'average_won_value_minor' => $funnel->averageWonValueMinor(),
            ],
            'by_source' => array_map(static fn (LeadSourceRow $row): array => [
                'source_kind' => $row->sourceKind->value,
                'source_kind_label' => $row->sourceKind->label(),
                'source_detail' => $row->sourceDetail,
                'is_measured' => $row->isMeasured(),
                'total' => $row->total,
                'qualified' => $row->qualified,
                'won' => $row->won,
                'closed' => $row->closed,
                'won_value_minor' => $row->wonValueMinor,
                'currency' => $row->currency,
                'win_rate' => $row->winRate(),
            ], $view->bySource),
            'by_campaign' => array_map(static fn (LeadBreakdownRow $row): array => [
                'label' => $row->label,
                'total' => $row->total,
                'qualified' => $row->qualified,
                'won' => $row->won,
                'closed' => $row->closed,
                'evidence' => $row->evidence?->value,
                'evidence_label' => $row->evidence?->label(),
                'confidence' => $row->confidence(),
                'is_unattributed' => $row->isUnattributed(),
                'win_rate' => $row->winRate(),
            ], $view->byCampaign),
            /*
             * Counted taps and forms begun, over the same window, **beside the funnel and never inside it.**
             * These numbers are always larger — often tenfold — and no ratio between the two columns is
             * published: a tap and a lead do not share a denominator, so the gap is information and a
             * percentage would be a claim.
             */
            'signals' => $view->signals,
            'signals_note' => 'Counted taps and form starts. Not leads, and not comparable as a ratio: the same person taps several times, or taps and then phones instead.',
        ];
    }
}
