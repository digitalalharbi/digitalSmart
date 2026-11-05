<?php

declare(strict_types=1);

namespace App\Application\Reporting\Subscription;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Reporting\EmailCadence;

/**
 * Add somebody outside the agency as a recipient of a report's periodic email.
 *
 * Three things are required and none is defaulted. The report must be client-safe — the same rule a share link has,
 * because the email carries one. The caller must hold `reports.share`: writing to a client's inbox is sending, and
 * sending is the permission a buyer does not have. And somebody must say how the recipient agreed: an address typed
 * into a form is not consent, and the note is what answers "why are you emailing me" a year later.
 *
 * Nothing is sent here. The first email goes on the next scheduled run, through the same checks as every later one.
 */
final readonly class SubscribeReportRecipient
{
    public function __construct(
        private ReportRepository $reports,
        private ReportSubscriptionStore $subscriptions,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function __invoke(
        string $reportId,
        string $email,
        ?string $name,
        string $locale,
        EmailCadence $cadence,
        string $consentNote,
    ): ReportSubscription {
        $this->guard->ensure(Permission::ReportsShare);

        $workspaceId = $this->actor->workspaceId()->value;
        $report = $this->reports->find($workspaceId, $this->visibility->visibleProjectIds(), $reportId)
            ?? throw ResourceNotFound::of('Report');

        if ($report->definition->audience->allowsInternalNotes()) {
            throw InvalidSubscription::reportIsInternal();
        }

        $email = mb_strtolower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw InvalidSubscription::emailInvalid();
        }

        if (trim($consentNote) === '') {
            throw InvalidSubscription::consentMissing();
        }

        if ($this->subscriptions->exists($workspaceId, $reportId, $email)) {
            throw InvalidSubscription::alreadySubscribed();
        }

        $subscription = $this->subscriptions->create(
            $workspaceId,
            $reportId,
            $email,
            $name === null || trim($name) === '' ? null : trim($name),
            $locale === 'en' ? 'en' : 'ar',
            $cadence,
            trim($consentNote),
            // A placeholder nobody holds: the real credential is minted with each email and never stored in the clear.
            hash('sha256', bin2hex(random_bytes(32))),
            $this->actor->userId()->value,
            $this->clock->now(),
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ReportRecipientAdded,
            auditableType: 'report_email_subscription',
            auditableId: $subscription->id,
            summary: sprintf('Added an email recipient to "%s" (%s)', $report->definition->name, $cadence->value),
            // The address is the disclosure being recorded, so it is in the entry; the consent note is too.
            changes: ['report_id' => $reportId, 'recipient_email' => $email, 'cadence' => $cadence->value, 'consent_note' => trim($consentNote), 'client_id' => $report->definition->clientId],
        ));

        return $subscription;
    }
}
