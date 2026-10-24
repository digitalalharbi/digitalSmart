<?php

declare(strict_types=1);

namespace App\Application\Access\Query;

use App\Application\Access\Contract\AccessInvitationRepository;
use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Access\DTO\AccessEventView;
use App\Application\Access\DTO\AccessRequestView;
use App\Application\Access\DTO\AccessSettings;
use App\Application\Access\DTO\InvitationView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\OwnerDirectory;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\AccessRequestStatus;
use App\Domain\Access\Permission;
use App\Domain\Leads\Automation\LeadSla;
use App\Domain\Leads\Automation\SlaState;
use DateTimeImmutable;

/**
 * Everything the inbox, a request's page and the small operational figures read.
 *
 * The response-time state is judged by the lead pipeline's own rule (`LeadSla`): an attempt and a contact are two clocks, and a
 * request that has been tried but not reached is `attempted`, never `contacted`.
 */
final readonly class ReadAccessRequests
{
    public function __construct(
        private AccessRequestRepository $requests,
        private AccessInvitationRepository $invitations,
        private OwnerDirectory $owners,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private Clock $clock,
    ) {}

    /**
     * @param  list<AccessRequestStatus>|null  $statuses
     * @return array{rows: list<array{view: AccessRequestView, sla: array{state: string, due_at: DateTimeImmutable, met: bool|null}|null}>, counts: array<string, int>, settings: AccessSettings, owners: list<array{id: string, name: string}>}
     */
    public function inbox(?array $statuses, ?string $ownerId, int $limit = 100): array
    {
        $this->guard->ensure(Permission::UsersInvite);

        $workspace = $this->actor->workspaceId()->value;
        $settings = $this->requests->settings($workspace);
        $now = $this->clock->now();

        $rows = array_map(
            fn (AccessRequestView $view): array => ['view' => $view, 'sla' => $this->sla($view, $settings->responseMinutes, $now)],
            $this->requests->list($workspace, $statuses, $ownerId, max(1, min($limit, 200))),
        );

        return [
            'rows' => $rows,
            'counts' => $this->requests->counts($workspace),
            'settings' => $settings,
            'owners' => array_map(
                static fn ($c): array => ['id' => $c->id, 'name' => $c->name],
                $this->owners->peopleWhoCanSee($workspace, null, Permission::UsersInvite),
            ),
        ];
    }

    /**
     * @return array{view: AccessRequestView, sla: array{state: string, due_at: DateTimeImmutable, met: bool|null}|null, events: list<AccessEventView>, linked: list<AccessRequestView>, invitation: InvitationView|null}
     */
    public function one(string $id): array
    {
        $this->guard->ensure(Permission::UsersInvite);

        $workspace = $this->actor->workspaceId()->value;
        $view = $this->requests->find($workspace, $id) ?? throw ResourceNotFound::of('Request');
        $settings = $this->requests->settings($workspace);

        return [
            'view' => $view,
            'sla' => $this->sla($view, $settings->responseMinutes, $this->clock->now()),
            'events' => $this->requests->events($workspace, $id),
            'linked' => $this->requests->linked($workspace, $id),
            'invitation' => $this->invitations->latestFor($workspace, $id),
        ];
    }

    /**
     * Small operational figures over the last `$days` days. Counts of what happened to requests, and no funnel: these are
     * tens of people, not a marketing audience.
     *
     * @return array<string, int|float|null>
     */
    public function figures(int $days): array
    {
        $this->guard->ensure(Permission::UsersInvite);

        $workspace = $this->actor->workspaceId()->value;
        $now = $this->clock->now();
        $since = $now->modify(sprintf('-%d days', max(1, min($days, 365))));
        $settings = $this->requests->settings($workspace);
        $rows = $this->requests->createdSince($workspace, $since, 5000);

        $seconds = [];
        $judged = 0;
        $met = 0;

        foreach ($rows as $row) {
            $sla = LeadSla::judge($row->createdAt, $row->firstAttemptAt, $row->firstContactedAt, $settings->responseMinutes, $now);

            if ($row->firstAttemptAt !== null) {
                $seconds[] = max(0, $row->firstAttemptAt->getTimestamp() - $row->createdAt->getTimestamp());
            }

            /* Judged only once there is something to judge: a tried request, or one whose time ran out. */
            if ($sla->met !== null) {
                $judged++;
                $met += $sla->met ? 1 : 0;
            }
        }

        sort($seconds);
        $count = count($seconds);
        $median = $count === 0 ? null : ($count % 2 === 1 ? $seconds[intdiv($count, 2)] : (int) round(($seconds[$count / 2 - 1] + $seconds[$count / 2]) / 2));
        $acceptance = $this->invitations->acceptance($workspace, $since);

        return [
            'days' => max(1, min($days, 365)),
            'new_requests' => count($rows),
            'contacted' => count(array_filter($rows, static fn (AccessRequestView $r): bool => $r->firstContactedAt !== null)),
            'qualified' => count(array_filter($rows, static fn (AccessRequestView $r): bool => $r->status === AccessRequestStatus::Qualified)),
            'approved' => count(array_filter($rows, static fn (AccessRequestView $r): bool => $r->reviewedAt !== null && $r->status !== AccessRequestStatus::Rejected)),
            'rejected' => count(array_filter($rows, static fn (AccessRequestView $r): bool => $r->status === AccessRequestStatus::Rejected)),
            'median_first_response_seconds' => $median,
            'sla_judged' => $judged,
            'sla_compliance' => $judged === 0 ? null : round($met / $judged, 4),
            'invitations_made' => $acceptance['made'],
            'invitations_accepted' => $acceptance['accepted'],
            'invitation_acceptance' => $acceptance['made'] === 0 ? null : round($acceptance['accepted'] / $acceptance['made'], 4),
            'response_minutes' => $settings->responseMinutes,
        ];
    }

    /** @return array{state: string, due_at: DateTimeImmutable, met: bool|null} */
    private function sla(AccessRequestView $view, int $minutes, DateTimeImmutable $now): array
    {
        $judged = LeadSla::judge($view->createdAt, $view->firstAttemptAt, $view->firstContactedAt, $minutes, $now);

        /* A request that was put away without being tried is not "overdue" any more; it is simply not an open matter. */
        if (! $view->status->isOpen() && $judged->state === SlaState::Overdue) {
            return ['state' => 'closed', 'due_at' => $judged->dueAt, 'met' => false];
        }

        return ['state' => $judged->state->value, 'due_at' => $judged->dueAt, 'met' => $judged->met];
    }
}
