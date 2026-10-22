<?php

declare(strict_types=1);

namespace App\Application\Reporting\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use DateTimeImmutable;

/**
 * Every link out of the agency, across its reports — the answer to "what can somebody outside see right now?".
 *
 * Its own permission, the one that lets a person create a link: a list of links is a list of the doors, and somebody
 * who may not open one has no business reading where each leads. Limited to the reports the reader may see.
 */
final readonly class ListReportShares
{
    private const int LIMIT = 200;

    public function __construct(
        private ReportRepository $reports,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /**
     * @return list<array{id: string, report_id: string, report_name: string, client_name: ?string, hint: string, snapshot_id: ?string, expires_at: DateTimeImmutable, revoked_at: ?DateTimeImmutable, revoked_reason: ?string, recipient_label: ?string, view_count: int, last_viewed_at: ?DateTimeImmutable, created_by_name: ?string, created_at: DateTimeImmutable}>
     */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::ReportsShare);

        return $this->reports->sharesAcross(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            self::LIMIT,
        );
    }
}
