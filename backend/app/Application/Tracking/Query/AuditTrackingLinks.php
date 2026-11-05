<?php

declare(strict_types=1);

namespace App\Application\Tracking\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\DTO\TrackingLinkView;
use App\Domain\Access\Permission;
use App\Domain\Tracking\LinkStatus;
use App\Domain\Tracking\UtmAudit;

/**
 * The campaign-parameter audit of the links the reader may see.
 *
 * Read through the same repository and the same visibility as the link list, so the audit cannot name a link its
 * reader could not open. Archived links are left out — nobody is going to fix one — and a duplicate set is only ever
 * reported between links the reader can see, so it cannot disclose that a hidden project's link exists.
 */
final readonly class AuditTrackingLinks
{
    public function __construct(
        private TrackingLinkRepository $links,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /**
     * @return array{audited: int, with_findings: int, by_code: array<string, int>, links: list<array{id: string, name: string, project_id: string, project_name: string, client_name: string, status: string, findings: list<array{code: string, parameters: list<string>, shared_with: list<string>}>}>}
     */
    public function __invoke(?string $projectId = null, ?string $clientId = null): array
    {
        $this->guard->ensure(Permission::TrackingView);

        $rows = array_values(array_filter(
            $this->links->list($this->actor->workspaceId()->value, $this->visibility->visibleProjectIds(), $projectId, $clientId, null),
            static fn (TrackingLinkView $link): bool => $link->status !== LinkStatus::Archived,
        ));

        $findings = UtmAudit::judge(array_map(static fn (TrackingLinkView $link): array => [
            'id' => $link->id,
            'project_id' => $link->projectId,
            'live' => $link->status === LinkStatus::Active,
            'destination_url' => $link->destinationUrl,
            'utm' => $link->utm,
        ], $rows));

        $byCode = [];
        $links = [];

        foreach ($rows as $link) {
            if (! isset($findings[$link->id])) {
                continue;
            }

            foreach ($findings[$link->id] as $finding) {
                $byCode[$finding['code']] = ($byCode[$finding['code']] ?? 0) + 1;
            }

            $links[] = [
                'id' => $link->id,
                'name' => $link->name,
                'project_id' => $link->projectId,
                'project_name' => $link->projectName,
                'client_name' => $link->clientName,
                'status' => $link->status->value,
                'findings' => $findings[$link->id],
            ];
        }

        ksort($byCode);

        return ['audited' => count($rows), 'with_findings' => count($links), 'by_code' => $byCode, 'links' => $links];
    }
}
