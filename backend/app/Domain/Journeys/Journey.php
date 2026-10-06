<?php

declare(strict_types=1);

namespace App\Domain\Journeys;

use App\Domain\Commerce\AttributionEvidence;
use DateTimeImmutable;

/**
 * What one person did, in order, and how sure we are it is one person.
 *
 * Two refusals are built in.
 *
 * **It will not call a single record a journey.** `isMultiTouch()` exists so an interface can say "one
 * enquiry, nothing else recorded" rather than drawing a one-step funnel — which reads as a complete picture of
 * a customer who in fact has a history this product cannot see.
 *
 * **It will not attribute the whole journey to one campaign.** Each touchpoint keeps the attribution it
 * carried; the journey does not acquire one. Deciding which touch earned the credit is a *model*, it belongs to
 * the attribution phase, and it must be labelled as modelled when it arrives. A journey object that quietly
 * exposed `->campaign` would be that model with no label.
 */
final readonly class Journey
{
    /** @param list<Touchpoint> $touchpoints ordered oldest first */
    public function __construct(
        public array $touchpoints,
        /** What makes us think these are one person. */
        public IdentityEvidence $identityEvidence,
        public ?string $name = null,
    ) {}

    public function touchpointCount(): int
    {
        return count($this->touchpoints);
    }

    /**
     * Whether there is more than one thing here.
     *
     * The guard against a one-step funnel being read as a whole customer history.
     */
    public function isMultiTouch(): bool
    {
        return count($this->touchpoints) > 1;
    }

    public function first(): ?Touchpoint
    {
        return $this->touchpoints[0] ?? null;
    }

    public function last(): ?Touchpoint
    {
        return $this->touchpoints === [] ? null : $this->touchpoints[count($this->touchpoints) - 1];
    }

    /** Whether this identity is solid enough to count in a report rather than merely to show somebody. */
    public function isReliable(): bool
    {
        return $this->identityEvidence->isReliable();
    }

    /**
     * Days between the first and last thing recorded, or null where there is only one.
     *
     * Null rather than zero, because "nothing elapsed" and "there is nothing to measure" are different facts —
     * and a zero would be averaged into a cycle-time figure as though the customer had bought instantly.
     */
    public function spanInDays(): ?float
    {
        $first = $this->first();
        $last = $this->last();

        if ($first === null || $last === null || $first === $last) {
            return null;
        }

        return round(($last->occurredAt->getTimestamp() - $first->occurredAt->getTimestamp()) / 86_400, 1);
    }

    /** The total of every value recorded on this journey, where any was. */
    public function recordedValueMinor(): int
    {
        $total = 0;

        foreach ($this->touchpoints as $touchpoint) {
            $total += $touchpoint->valueMinor ?? 0;
        }

        return $total;
    }

    /**
     * The attribution of the **earliest** touchpoint that carried any.
     *
     * Offered as a fact about that record — "the first thing we have from this person came from this campaign"
     * — and explicitly **not** as the journey's attribution. First-touch is one of several models and the
     * others disagree with it; choosing between them is the attribution phase's job, and whatever it chooses
     * has to be labelled as modelled.
     */
    public function earliestCarriedAttribution(): ?Touchpoint
    {
        foreach ($this->touchpoints as $touchpoint) {
            if ($touchpoint->attributionEvidence !== AttributionEvidence::None) {
                return $touchpoint;
            }
        }

        return null;
    }

    public function firstOccurredAt(): ?DateTimeImmutable
    {
        return $this->first()?->occurredAt;
    }
}
