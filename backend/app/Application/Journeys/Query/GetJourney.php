<?php

declare(strict_types=1);

namespace App\Application\Journeys\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Journeys\Contract\JourneyReadModel;
use App\Application\Journeys\DTO\JourneyView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/**
 * What one person did, with what is missing from it stated out loud.
 *
 * The limitations are part of the answer rather than a caveat in the documentation. A reader looking at one
 * enquiry and one order has to know the clicks, sessions and impressions before them are **absent rather than
 * nonexistent** — otherwise the obvious reading is that this customer arrived from nowhere, which is both wrong
 * and the sort of wrong somebody acts on.
 *
 * Gated on `leads.view`, because a journey is mostly a person's enquiries and carries their contact details.
 */
final readonly class GetJourney
{
    public function __construct(
        private JourneyReadModel $journeys,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(string $leadId): JourneyView
    {
        $this->guard->ensure(Permission::LeadsView);

        $journey = $this->journeys->forLead(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $leadId,
        );

        if ($journey === null) {
            throw ResourceNotFound::of('Lead');
        }

        return new JourneyView($journey, $this->limitations($journey));
    }

    /**
     * What this journey does not contain, in words a reader can act on.
     *
     * Assembled per journey rather than written once on the page, because which limitation matters depends on
     * what came back: a single-touch journey needs to be told it is single-touch, and a weakly linked one needs
     * to be told why.
     *
     * @return list<string>
     */
    private function limitations(\App\Domain\Journeys\Journey $journey): array
    {
        $limitations = [
            /*
             * The one that matters most, and the reason this phase does not pretend to do more. GA4's Data API
             * returns aggregated reports rather than per-user event streams, and the documented route to
             * event-level data is a BigQuery export this product does not read. So the marketing touches before
             * a conversion are simply not here.
             */
            'Only enquiries and orders are shown. The clicks, sessions and ad impressions before them are not '
            .'available to this product yet — they need event-level data, which Google Analytics provides through '
            .'a BigQuery export rather than through its reporting API.',
        ];

        if (! $journey->isMultiTouch()) {
            $limitations[] = 'Only one record exists for this person, so this is not a journey yet — it is a '
                .'single event, and anything before it is unrecorded rather than absent.';
        }

        if (! $journey->isReliable() && $journey->touchpointCount() > 1) {
            $limitations[] = 'These records are linked on weaker evidence than a contact match. Treat them as '
                .'possibly the same person rather than certainly so.';
        }

        return $limitations;
    }
}
