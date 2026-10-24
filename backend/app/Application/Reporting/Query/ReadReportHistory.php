<?php

declare(strict_types=1);

namespace App\Application\Reporting\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Reporting\Contract\ReportShareStore;
use App\Application\Reporting\DTO\ShareView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/**
 * One report's frozen records and links out.
 *
 * Both were read straight from the controller with only the workspace in the query: another tenant's report id answered
 * an empty 200, and a reader without the permission, or without sight of the report's client, could list its links.
 * The report is now found the way every other read finds it — by workspace **and** visibility — or it is not found.
 */
final readonly class ReadReportHistory
{
    private const int SNAPSHOT_LIMIT = 50;

    public function __construct(
        private ReportRepository $reports,
        private ReportShareStore $shares,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<array<string, mixed>> */
    public function snapshots(string $reportId): array
    {
        $this->guard->ensure(Permission::ReportsView);
        $this->visible($reportId);

        return array_values($this->reports->snapshots($this->actor->workspaceId()->value, $reportId, self::SNAPSHOT_LIMIT));
    }

    /** @return list<ShareView> */
    public function shares(string $reportId): array
    {
        $this->guard->ensure(Permission::ReportsShare);
        $this->visible($reportId);

        return array_values($this->shares->forReport($this->actor->workspaceId()->value, $reportId));
    }

    private function visible(string $reportId): void
    {
        $this->reports->find($this->actor->workspaceId()->value, $this->visibility->visibleProjectIds(), $reportId)
            ?? throw ResourceNotFound::of('Report');
    }
}
