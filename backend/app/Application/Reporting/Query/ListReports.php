<?php

declare(strict_types=1);

namespace App\Application\Reporting\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Reporting\DTO\ReportSummary;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

/**
 * Saved reports.
 *
 * Deliberately does not render any of them. A list that rendered every report would run an analytics aggregation
 * per row and throw the figures away the moment somebody clicked into one — which is how a list page becomes the
 * slowest screen in a product.
 */
final readonly class ListReports
{
    private const int LIMIT = 100;

    public function __construct(
        private ReportRepository $reports,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<ReportSummary> */
    public function __invoke(?string $clientId = null, ?int $limit = null): array
    {
        $this->guard->ensure(Permission::ReportsView);

        return $this->reports->list(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $clientId,
            max(1, min($limit ?? self::LIMIT, 200)),
        );
    }
}
