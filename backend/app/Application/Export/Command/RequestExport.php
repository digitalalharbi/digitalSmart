<?php

declare(strict_types=1);

namespace App\Application\Export\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Export\Contract\ExportJobStore;
use App\Application\Export\DTO\ExportView;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Saas\EntitlementGuard;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Export\ExportKind;
use App\Domain\Saas\Limit;

/**
 * Ask for a heavy file. Returns at once with a queued job; the work happens on the `reports` queue.
 *
 * The subject is checked **here**, as the requester, before anything is queued — so a report somebody may not
 * see is a 404 now rather than a job that fails later with a revealing message.
 */
final readonly class RequestExport
{
    public function __construct(
        private ExportJobStore $jobs,
        private ReportRepository $reports,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
        private EntitlementGuard $entitlements,
    ) {}

    public function __invoke(ExportKind $kind, string $subjectId): ExportView
    {
        $this->guard->ensure(Permission::ReportsView);

        $workspaceId = $this->actor->workspaceId()->value;

        $this->reports->find($workspaceId, $this->visibility->visibleProjectIds(), $subjectId)
            ?? throw ResourceNotFound::of('Report');

        return $this->entitlements->claim(Limit::ExportsMonthly, fn (): ExportView => $this->jobs->create($workspaceId, $this->actor->userId()->value, $kind, $subjectId, $this->clock->now()));
    }
}
