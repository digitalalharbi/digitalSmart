<?php

declare(strict_types=1);

namespace App\Application\Reporting\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Reporting\Contract\ReportShareStore;
use App\Application\Reporting\DTO\ShareView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Reporting\Exception\InvalidShare;
use DateTimeImmutable;

/**
 * Create a link that lets somebody outside the workspace read one report.
 *
 * `reports.share` rather than `reports.manage`: building a document and handing a client's performance data to
 * whoever holds a URL are different acts, and the second is the one somebody will be asked about.
 *
 * Three refusals, each protecting something a link cannot take back:
 *
 *  - **An internal report cannot be shared.** Not "shared with the notes stripped" — refused. A report written with
 *    internal commentary in mind has internal commentary throughout it: in its section titles, in which metrics
 *    were chosen, in what was left out. Stripping one field would produce a document that looks client-safe and was
 *    not written to be.
 *  - **Every link expires**, and the maximum is ninety days. A link with no end outlives the engagement, the
 *    account manager and sometimes the relationship.
 *  - **A snapshot being shared must belong to this report.** Otherwise a share could serve one client's figures
 *    from another client's link.
 */
final readonly class ShareReport
{
    /**
     * The longest a link may live.
     *
     * Ninety days. Long enough for a quarterly conversation, short enough that a forgotten link lapses inside one.
     * A judgement rather than a measurement, which is why it is a named constant with this sentence beside it.
     */
    public const int MAX_DAYS = 90;

    public function __construct(
        private ReportRepository $reports,
        private ReportShareStore $shares,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    /** @return array{share: ShareView, token: string} */
    public function __invoke(
        string $reportId,
        int $days,
        ?string $snapshotId = null,
        ?string $recipientLabel = null,
    ): array {
        $this->guard->ensure(Permission::ReportsShare);

        $workspaceId = $this->actor->workspaceId()->value;

        $report = $this->reports->find($workspaceId, $this->visibility->visibleProjectIds(), $reportId)
            ?? throw ResourceNotFound::of('Report');

        if ($report->definition->audience->allowsInternalNotes()) {
            // Refused rather than sanitised. See the note at the top of this class.
            throw InvalidShare::reportIsInternal();
        }

        if ($days < 1 || $days > self::MAX_DAYS) {
            throw InvalidShare::expiryOutOfRange(self::MAX_DAYS);
        }

        if ($snapshotId !== null) {
            $snapshot = $this->reports->findSnapshot($workspaceId, $snapshotId);

            if ($snapshot === null) {
                throw ResourceNotFound::of('Snapshot');
            }

            /*
             * Belonging to this report, not merely existing. Without this a share could serve one client's figures
             * from another client's link — the worst single failure available to this module.
             */
            $belongs = false;

            foreach ($this->reports->snapshots($workspaceId, $reportId, 500) as $candidate) {
                if ($candidate['id'] === $snapshotId) {
                    $belongs = true;

                    break;
                }
            }

            if (! $belongs) {
                throw ResourceNotFound::of('Snapshot');
            }
        }

        $expiresAt = $this->clock->now()->modify('+'.$days.' days');

        $created = $this->shares->create(
            $workspaceId,
            $reportId,
            $snapshotId,
            $expiresAt,
            $recipientLabel,
            $this->actor->userId()->value,
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ReportShared,
            auditableType: 'report_share',
            auditableId: $created['share']->id,
            summary: sprintf(
                'Shared "%s" until %s',
                $report->definition->name,
                $expiresAt->format('Y-m-d'),
            ),
            changes: [
                'report_id' => $reportId,
                /*
                 * The hint, never the token. An audit log that recorded the credential would be a second place the
                 * credential lives — and the one with the longest retention.
                 */
                'token_hint' => $created['share']->hint,
                'expires_at' => $expiresAt->format(DateTimeImmutable::ATOM),
                'recipient_label' => $recipientLabel,
                /* Whether the link is frozen or recomputes, which decides what a recipient can see later. */
                'is_frozen' => $snapshotId !== null,
                'client_id' => $report->definition->clientId,
            ],
        ));

        return $created;
    }
}
