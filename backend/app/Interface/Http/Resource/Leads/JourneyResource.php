<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Leads;

use App\Application\Journeys\DTO\JourneyView;
use App\Domain\Journeys\Touchpoint;

final readonly class JourneyResource
{
    /** @return array<string, mixed> */
    public static function journey(JourneyView $view): array
    {
        $journey = $view->journey;

        return [
            'name' => $journey->name,
            /*
             * How sure we are these records are one person, and whether that is solid enough to count in a
             * report rather than merely to show somebody.
             */
            'identity_evidence' => $journey->identityEvidence->value,
            'identity_evidence_label' => $journey->identityEvidence->label(),
            'identity_confidence' => $journey->identityEvidence->confidence(),
            'is_reliable' => $journey->isReliable(),
            'touchpoint_count' => $journey->touchpointCount(),
            /*
             * The guard against a one-step funnel being read as a whole customer history. An interface shows
             * "one enquiry, nothing else recorded" rather than drawing a journey from a single point.
             */
            'is_multi_touch' => $journey->isMultiTouch(),
            'span_in_days' => $journey->spanInDays(),
            'recorded_value_minor' => $journey->recordedValueMinor(),
            /*
             * The earliest touchpoint that carried an attribution, offered as a fact about *that record* and
             * explicitly not as the journey's attribution. First-touch is one model among several and the others
             * disagree with it; choosing between them belongs to the attribution phase, and whatever it chooses
             * must be labelled as modelled.
             */
            'earliest_attributed_touchpoint' => $journey->earliestCarriedAttribution() === null
                ? null
                : self::touchpoint($journey->earliestCarriedAttribution()),
            'touchpoints' => array_map(
                static fn (Touchpoint $touchpoint): array => self::touchpoint($touchpoint),
                $journey->touchpoints,
            ),
            /*
             * What is missing, in the response rather than in the documentation. A reader seeing one enquiry and
             * one order must know the marketing before them is absent rather than nonexistent.
             */
            'limitations' => $view->limitations,
        ];
    }

    /** @return array<string, mixed> */
    private static function touchpoint(Touchpoint $touchpoint): array
    {
        return [
            'kind' => $touchpoint->kind->value,
            'kind_label' => $touchpoint->kind->label(),
            'id' => $touchpoint->id,
            'occurred_at' => $touchpoint->occurredAt->format(DATE_ATOM),
            'summary' => $touchpoint->summary,
            'state' => $touchpoint->state,
            // What this record itself carried. Never inherited from another touchpoint.
            'attribution_evidence' => $touchpoint->attributionEvidence->value,
            'attribution_evidence_label' => $touchpoint->attributionEvidence->label(),
            'attribution_confidence' => $touchpoint->attributionEvidence->confidence(),
            'campaign' => $touchpoint->campaign,
            'source' => $touchpoint->source,
            'value_minor' => $touchpoint->valueMinor,
            'currency' => $touchpoint->currency,
        ];
    }
}
