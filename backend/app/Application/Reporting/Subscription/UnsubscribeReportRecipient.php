<?php

declare(strict_types=1);

namespace App\Application\Reporting\Subscription;

use App\Application\Shared\Contract\AuditRecorder;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Workspace\WorkspaceId;

/**
 * The recipient's own "stop": no account, no session, one credential from the email.
 *
 * It always answers the same way. Telling a caller that a token was unknown would tell anybody guessing tokens when
 * they had guessed one, and the person who pressed the link twice should not be told the second press failed.
 */
final readonly class UnsubscribeReportRecipient
{
    public function __construct(private ReportSubscriptionStore $subscriptions, private AuditRecorder $audit) {}

    public function __invoke(string $token): void
    {
        // Sixty-four hex characters or nothing: the store is never asked about arbitrary text.
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return;
        }

        $subscription = $this->subscriptions->unsubscribeByTokenHash(hash('sha256', $token));

        if ($subscription === null) {
            return;
        }

        // No actor: nobody in the workspace did this. The entry says the recipient did, which is the fact.
        $this->audit->record(new AuditEntry(
            workspaceId: new WorkspaceId($subscription->workspaceId),
            actorId: null,
            action: AuditAction::ReportRecipientUnsubscribed,
            auditableType: 'report_email_subscription',
            auditableId: $subscription->id,
            summary: 'A report email recipient unsubscribed using the link in the email',
            changes: ['report_id' => $subscription->reportId, 'recipient_email' => $subscription->recipientEmail],
        ));
    }
}
