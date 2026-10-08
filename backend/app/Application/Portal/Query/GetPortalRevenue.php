<?php

declare(strict_types=1);

namespace App\Application\Portal\Query;

use App\Application\Commerce\Contract\StoreAnalyticsReadModel;
use App\Application\Commerce\DTO\StoreAnalyticsView;
use App\Application\Commerce\DTO\StoreQuery;
use App\Application\Portal\Service\PortalAccess;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Portal\Exception\RevenueSourceRequired;
use App\Domain\Portal\PortalModule;
use App\Domain\Shared\ValueObject\DateRange;

/**
 * What the client's store sold, according to one named authority.
 *
 * **The revenue source stays a required argument, even here.** It would be easy to argue that a client should not
 * be asked to choose — and then to choose for them, which is how a portal comes to show a store total labelled as
 * advertising revenue. A Salla total counts invoices; a GA4 total counts what a browser reported before the
 * payment may have failed. They are different claims about the same period, neither is wrong, and naming which
 * one is on the page is the only version of this surface an agency can defend.
 *
 * What the portal does instead of hiding the choice is make it a small one: the sources available are the ones
 * that have data for this client, and whichever is chosen is printed beside every figure.
 */
final readonly class GetPortalRevenue
{
    /** Enough to answer "how did last month go" without inviting a reader to build their own analysis. */
    private const int ROW_LIMIT = 10;

    public function __construct(
        private StoreAnalyticsReadModel $analytics,
        private PortalAccess $access,
        private ActorContext $actor,
    ) {}

    /**
     * @throws RevenueSourceRequired when no authority was named — raised only once the reader is known to be
     *                               entitled to this surface at all.
     */
    public function __invoke(
        string $clientId,
        ?RevenueSource $revenueSource,
        DateRange $period,
    ): StoreAnalyticsView {
        $this->access->ensure($clientId, PortalModule::StoreRevenue);

        if ($revenueSource === null) {
            throw new RevenueSourceRequired;
        }

        if (! in_array($revenueSource, self::available(), true)) {
            /*
             * 404 rather than 403 or 422: as far as a portal reader is concerned this authority is not something
             * the surface offers, and a refusal naming it would be an invitation to ask why.
             */
            throw ResourceNotFound::of('Portal surface');
        }

        $query = new StoreQuery(
            workspaceId: $this->actor->workspaceId()->value,
            revenueSource: $revenueSource,
            from: $period->start,
            until: $period->end,
            /*
             * Null, and narrowed by the client id instead. The reader's own project assignments decide *which
             * client's* portal they reached; inside it, they read that client's whole account.
             */
            visibleProjectIds: null,
            clientId: $clientId,
            storeId: null,
            limit: self::ROW_LIMIT,
        );

        return new StoreAnalyticsView(
            summary: $this->analytics->summary($query),
            byChannel: $this->analytics->byChannel($query),
            byCampaign: $this->analytics->byCampaign($query),
            byProduct: $this->analytics->byProduct($query),
            daily: $this->analytics->daily($query),
            /*
             * The client's own store, so the client sees its mix. "How much of this month came from people who had
             * not bought before" is a question about their business rather than about the agency, and the three
             * buckets carry their own honesty — including the one that says this product cannot tell yet.
             */
            customerMix: $this->analytics->customerMix($query),
            from: $period->start,
            until: $period->end,
        );
    }

    /**
     * Which authorities a client may be shown, and the one they may not.
     *
     * **Attributed revenue is absent.** It is a model, and a modelled figure read as a record is the most
     * expensive mistake this product can cause — which is exactly why attribution is in `WithheldModule` and
     * never a portal surface. Offering it here as a revenue source would be the same disclosure through a
     * different door: the figure needs its coverage caveat explained by somebody who can answer a question
     * about it, and on a portal page nobody is.
     *
     * @return list<RevenueSource>
     */
    public static function available(): array
    {
        return array_values(array_filter(
            RevenueSource::cases(),
            static fn (RevenueSource $source): bool => $source !== RevenueSource::Attributed,
        ));
    }
}
