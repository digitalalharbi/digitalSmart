<?php

declare(strict_types=1);

namespace App\Application\Reporting\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Advertising\MetricSource;
use DateTimeImmutable;

/** Every frozen record across the reports the reader may see — what a report said on the day it was taken. */
final readonly class ListReportSnapshots
{
    private const int LIMIT = 200;

    public function __construct(
        private ReportRepository $reports,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /**
     * @return list<array{id: string, report_id: string, report_name: string, client_name: ?string, taken_at: DateTimeImmutable, period_from: DateTimeImmutable, period_until: DateTimeImmutable, source: MetricSource, label: ?string, taken_by_name: ?string}>
     */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::ReportsView);

        return $this->reports->snapshotsAcross(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            self::LIMIT,
        );
    }
}
