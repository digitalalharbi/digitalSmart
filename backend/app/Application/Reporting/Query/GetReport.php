<?php

declare(strict_types=1);

namespace App\Application\Reporting\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Reporting\DTO\RenderedReport;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/** One report, rendered against today's data. */
final readonly class GetReport
{
    public function __construct(
        private ReportRepository $reports,
        private RenderReport $render,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(string $reportId): RenderedReport
    {
        $this->guard->ensure(Permission::ReportsView);

        $workspaceId = $this->actor->workspaceId()->value;

        $report = $this->reports->find($workspaceId, $this->visibility->visibleProjectIds(), $reportId)
            ?? throw ResourceNotFound::of('Report');

        return ($this->render)($workspaceId, $report);
    }
}
