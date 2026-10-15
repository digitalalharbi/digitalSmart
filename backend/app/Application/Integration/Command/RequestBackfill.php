<?php

declare(strict_types=1);

namespace App\Application\Integration\Command;

use App\Application\Integration\Contract\SyncCandidateSource;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Integration\Provider;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Ask for an account's history to be read — a chosen window, at the lowest priority there is.
 *
 * **The window is a choice from a short list, not a free field.** Thirty, ninety, a hundred and eighty or three
 * hundred and sixty-five days: wide enough for any real need, bounded so one click cannot be a year of reads
 * across a platform's rate limit. The work itself goes to the backfill queue, which drains last, so importing
 * history can never make this morning's view of live campaigns late.
 *
 * Only an account some project has bound and kept syncing can be backfilled: history of an account nobody has
 * asked to read would be a way around the binding that decides who may see it.
 */
final readonly class RequestBackfill
{
    /** @var list<int> */
    public const array WINDOWS = [30, 90, 180, 365];

    public function __construct(
        private SyncCandidateSource $candidates,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    /** @return array{workspace_id: string, account_id: string, provider: Provider, since: DateTimeImmutable, until: DateTimeImmutable, days: int} */
    public function __invoke(string $accountId, int $days): array
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        if (! in_array($days, self::WINDOWS, true)) {
            throw new InvalidArgumentException(sprintf('Choose one of %s days.', implode(', ', self::WINDOWS)));
        }

        $workspace = $this->actor->workspaceId();
        $candidate = null;

        foreach ($this->candidates->candidates($workspace) as $c) {
            if ($c->externalAccountId === $accountId) {
                $candidate = $c;

                break;
            }
        }

        if ($candidate === null) {
            throw ResourceNotFound::of('Bound account');
        }

        $until = $this->clock->now()->modify('-1 day');
        $since = $until->modify(sprintf('-%d days', $days - 1));

        $this->audit->record(new AuditEntry(
            workspaceId: $workspace,
            actorId: $this->actor->userId(),
            action: AuditAction::IntegrationBackfillRequested,
            auditableType: 'external_account',
            auditableId: $accountId,
            summary: sprintf('Asked for the last %d days of an account to be read.', $days),
            changes: ['window' => ['from' => $since->format('Y-m-d'), 'to' => $until->format('Y-m-d')]],
        ));

        return [
            'workspace_id' => $workspace->value,
            'account_id' => $accountId,
            'provider' => $candidate->provider,
            'since' => $since,
            'until' => $until,
            'days' => $days,
        ];
    }
}
