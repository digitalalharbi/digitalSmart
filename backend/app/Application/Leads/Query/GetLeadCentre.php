<?php

declare(strict_types=1);

namespace App\Application\Leads\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Leads\Contract\LeadReadModel;
use App\Application\Leads\DTO\LeadCentreView;
use App\Application\Measurement\Contract\MeasurementSourceRepository;
use App\Application\Measurement\Contract\ObservedEventReadModel;
use App\Application\Measurement\DTO\MeasurementSourceView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;
use App\Domain\Measurement\NormalizedEvent;

/**
 * The Lead Center.
 *
 * Leads on one side, **signals on the other, never added together.** That arrangement is the whole point of
 * this use case: the measurement layer counts WhatsApp taps, phone taps and forms begun, and those numbers
 * are always larger — often by a factor of ten — than the leads they produced.
 *
 * Showing them side by side answers the question an agency actually has ("are our taps turning into
 * conversations?") while making the thing it must never do impossible to do by accident. A product that
 * summed them would report a cost per lead a fraction of the real one, win the next month's budget on it, and
 * be unable to explain afterwards why the client's sales did not move.
 *
 * No ratio is computed between the two columns. A tap and a lead do not share a denominator: the same person
 * taps three times, taps and then phones instead, or taps on a device the form never loaded on. The gap is
 * information; a percentage would be a claim.
 */
final readonly class GetLeadCentre
{
    /** The window the figures cover. A month, which is what an agency reviews. */
    private const int WINDOW_DAYS = 30;

    public function __construct(
        private LeadReadModel $leads,
        private MeasurementSourceRepository $sources,
        private ObservedEventReadModel $observed,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    public function __invoke(?string $clientId = null, ?int $days = null): LeadCentreView
    {
        $this->guard->ensure(Permission::LeadsView);

        $workspaceId = $this->actor->workspaceId()->value;
        $visibleProjects = $this->visibility->visibleProjectIds();
        $windowDays = max(1, min($days ?? self::WINDOW_DAYS, 365));

        $until = $this->clock->now();
        $from = $until->modify('-'.($windowDays - 1).' days')->setTime(0, 0);

        return new LeadCentreView(
            funnel: $this->leads->funnel($workspaceId, $visibleProjects, $clientId, $from, $until),
            bySource: $this->leads->bySource($workspaceId, $visibleProjects, $clientId, $from, $until),
            byCampaign: $this->leads->byCampaign($workspaceId, $visibleProjects, $clientId, $from, $until),
            signals: $this->signals($workspaceId, $visibleProjects, $clientId, $windowDays),
            windowDays: $windowDays,
        );
    }

    /**
     * Counted intent signals over the same window.
     *
     * Only the events the vocabulary marks as intent-only — a tap, a form begun — because those are the ones
     * routinely mistaken for leads. A purchase or a form *submission* is not a signal and does not belong in
     * this column.
     *
     * @param  list<string>|null  $visibleProjects
     * @return array<string, int>
     */
    private function signals(
        string $workspaceId,
        ?array $visibleProjects,
        ?string $clientId,
        int $windowDays,
    ): array {
        $sources = $this->sources->visibleTo($workspaceId, $visibleProjects, $clientId);

        $sourceIds = array_map(
            static fn (MeasurementSourceView $source): string => $source->id,
            $sources,
        );

        if ($sourceIds === []) {
            return [];
        }

        $counts = $this->observed->countsByRawName($workspaceId, $sourceIds, $windowDays);
        $intentNames = $this->intentSignalNames();
        $signals = [];

        foreach ($counts as $rawName => $observed) {
            /*
             * Matched on the normalised vocabulary's own names rather than on a substring of the raw one. A
             * raw event called `whatsapp_lead` is not evidence of anything — the mapping is what decides what
             * a name means, and guessing here would reintroduce exactly the inference this product refuses.
             */
            if (in_array($rawName, $intentNames, true)) {
                $signals[$rawName] = $observed['count'];
            }
        }

        return $signals;
    }

    /**
     * The vocabulary's intent-only event names.
     *
     * @return list<string>
     */
    private function intentSignalNames(): array
    {
        return array_values(array_map(
            static fn (NormalizedEvent $event): string => $event->value,
            array_filter(
                NormalizedEvent::cases(),
                static fn (NormalizedEvent $event): bool => $event->isIntentSignalOnly(),
            ),
        ));
    }
}
