<?php

declare(strict_types=1);

namespace App\Application\Attribution\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Attribution\Contract\AttributionReadModel;
use App\Application\Attribution\DTO\AttributionQuery;
use App\Application\Attribution\DTO\AttributionView;
use App\Application\Attribution\Exception\ModelNotAvailable;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Attribution\AttributionCoverage;
use App\Domain\Attribution\AttributionModel;
use App\Domain\Commerce\RevenueSource;
use DateTimeImmutable;

/**
 * Attribute a period's revenue under one named model.
 *
 * The use case's whole job, besides asking the read model, is to make sure nothing leaves here
 * without the three things that stop an attributed figure being misread: **which model produced it**,
 * **how much of the period it accounts for**, and **what it cannot say**.
 *
 * It also refuses. Three of the six models need the sequence of marketing touches before a
 * conversion, which this product does not hold, and asking for one gets an error naming what is
 * missing rather than a plausible-looking number computed from a single touch.
 */
final readonly class GetAttribution
{
    public function __construct(
        private AttributionReadModel $attribution,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(
        AttributionModel $model,
        RevenueSource $revenueSource,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        ?string $clientId = null,
        int $lookbackDays = 30,
        int $limit = 20,
    ): AttributionView {
        /*
         * The same permission the rest of the reporting surface uses. Attribution is a view of money
         * already readable through analytics and commerce; a separate permission would let somebody
         * be granted the modelled view and denied the recorded one, which is backwards.
         */
        $this->guard->ensure(Permission::AnalyticsView);

        if (! $model->isAvailable()) {
            throw ModelNotAvailable::of($model);
        }

        $query = new AttributionQuery(
            workspaceId: $this->actor->workspaceId()->value,
            model: $model,
            revenueSource: $revenueSource,
            from: $from,
            until: $until,
            visibleProjectIds: $this->visibility->visibleProjectIds(),
            clientId: $clientId,
            // Clamped rather than trusted: a lookback of a decade would be a different product.
            lookbackDays: max(1, min($lookbackDays, 365)),
            limit: max(1, min($limit, 100)),
        );

        $result = $this->attribution->attribute($query);

        return new AttributionView(
            model: $model,
            revenueSource: $revenueSource,
            coverage: $result->coverage,
            rows: $result->rows,
            from: $from,
            until: $until,
            lookbackDays: $query->lookbackDays,
            unmatchedSpendMinor: $result->unmatchedSpendMinor,
            spendCurrency: $result->spendCurrency,
            caveats: $this->caveats($model, $revenueSource, $result->coverage, $query->lookbackDays),
        );
    }

    /**
     * What this answer cannot say.
     *
     * Assembled per request, because which of these applies depends on the model, the authority and
     * what the data turned out to look like. A fixed footnote would be ignored; a sentence that
     * appears only when it is true gets read.
     *
     * @return list<string>
     */
    private function caveats(
        AttributionModel $model,
        RevenueSource $revenueSource,
        AttributionCoverage $coverage,
        int $lookbackDays,
    ): array {
        $caveats = [];

        if ($model->isModelled()) {
            $caveats[] = sprintf(
                '%s is a model, not a measurement. First-touch, last-touch and every model between '
                .'them disagree about the same orders by design, so these figures are a claim about '
                .'which campaign deserves credit rather than a record of what happened.',
                $model->label(),
            );
        }

        if ($model->needsPerson()) {
            $caveats[] = sprintf(
                'Looks back %d days for an earlier conversion by the same person, matched on a '
                .'normalised phone number or e-mail address. A different window credits different '
                .'campaigns for the same orders, and an order with no contact details cannot be '
                .'looked back from at all.',
                $lookbackDays,
            );
        }

        $revenue = $coverage->revenueCoverage();

        if ($revenue !== null && ! $coverage->isReportable()) {
            $caveats[] = sprintf(
                'These campaigns account for %s%% of the period\'s revenue. The rest arrived with no '
                .'marketing information and is not divided among them — it is unknown, which is a '
                .'different thing from zero.',
                number_format($revenue, 1),
            );
        }

        if ($revenueSource->isModelled()) {
            $caveats[] = sprintf(
                'The revenue itself comes from %s, which is already a model of what was sold rather '
                .'than an invoice. Two models are stacked here.',
                $revenueSource->label(),
            );
        }

        /*
         * The join nobody should make silently. Spend is the ad platform's own figure and revenue is
         * the shop's; putting them on one row is useful and is two authorities on one line.
         */
        if ($this->hasJoinedSpend($coverage)) {
            $caveats[] = 'Spend comes from the ad platforms and revenue from the shop. They are two '
                .'authorities on one row, matched only where an order\'s campaign parameter equals an '
                .'ad campaign\'s name exactly.';
        }

        return $caveats;
    }

    private function hasJoinedSpend(AttributionCoverage $coverage): bool
    {
        return $coverage->campaignNamedConversions > 0;
    }
}
