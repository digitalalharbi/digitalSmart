<?php

declare(strict_types=1);

namespace App\Application\Analytics\Query;

use App\Application\Analytics\Contract\AnalyticsReadModel;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Analytics\DTO\AnalyticsRequest;
use App\Application\Analytics\DTO\AnalyticsResult;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Analytics\Dimension;

/**
 * Answer one analytics request: totals, series, and an optional breakdown.
 *
 * All three come from one call because a dashboard needs them together and
 * because they must agree: a scorecard whose total disagrees with the sum of its
 * own chart is the fastest way to lose a client's trust in a report. Computing
 * them from the same filters in the same request is what keeps them consistent.
 *
 * Visibility is applied here, before anything is aggregated. An actor without
 * `projects.view_all` sees only the projects they are assigned to — enforced by
 * narrowing the filter, not by hiding rows afterwards.
 */
final readonly class GetAnalytics
{
    public function __construct(
        private AnalyticsReadModel $analytics,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(AnalyticsRequest $request): AnalyticsResult
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $workspaceId = $this->actor->workspaceId()->value;
        $filters = $this->scope($request->filters);

        if ($filters === null) {
            /*
             * The actor is assigned to no projects, so there is nothing they may
             * see. An empty result, not an error: the screen says "nothing
             * assigned to you yet" rather than "forbidden", because being
             * unassigned is a normal state and not a failure.
             */
            return AnalyticsResult::empty($request);
        }

        $summary = $this->analytics->summary(
            $workspaceId,
            $request->range,
            $filters,
            $request->metrics,
            $request->compareTo,
        );

        $series = $this->analytics->series(
            $workspaceId,
            $request->range,
            $filters,
            $request->metrics,
        );

        $breakdown = $request->dimension instanceof Dimension
            ? $this->analytics->breakdown(
                $workspaceId,
                $request->range,
                $filters,
                $request->dimension,
                $request->metrics,
                $request->orderBy,
                $request->limit,
            )
            : [];

        return new AnalyticsResult(
            request: $request,
            summary: $summary,
            series: $series,
            breakdown: $breakdown,
        );
    }

    /**
     * Narrow the caller's filters to what they are allowed to see.
     *
     * Returns null when the actor may see nothing at all.
     */
    private function scope(AnalyticsFilters $filters): ?AnalyticsFilters
    {
        $visible = $this->visibility->visibleProjectIds();

        if ($visible === null) {
            // Unrestricted: the caller's own filters stand as given.
            return $filters;
        }

        if ($visible === []) {
            return null;
        }

        $requested = $filters->projectIds;

        /*
         * Intersect rather than replace. A caller who asked for one project must
         * not silently receive all of theirs, and a caller who asked for someone
         * else's must not receive it at all.
         */
        $allowed = $requested === []
            ? $visible
            : array_values(array_intersect($requested, $visible));

        if ($allowed === []) {
            return null;
        }

        return new AnalyticsFilters(
            providers: $filters->providers,
            externalAccountIds: $filters->externalAccountIds,
            campaignIds: $filters->campaignIds,
            adGroupIds: $filters->adGroupIds,
            adIds: $filters->adIds,
            projectIds: $allowed,
            clientId: $filters->clientId,
        );
    }
}
