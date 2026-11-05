<?php

declare(strict_types=1);

namespace App\Application\Reporting\Subscription;

use App\Application\Notifications\Mail\EmailKind;
use App\Application\Notifications\Mail\EmailRenderer;
use App\Application\Notifications\Mail\MailTransport;
use App\Application\Reporting\Command\ShareReport;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\Clock;
use App\Domain\Identity\UserId;
use App\Domain\Reporting\EmailDeliveryState;
use App\Domain\Workspace\WorkspaceId;
use Throwable;

/**
 * Email each due recipient a fresh link to their report — once per period, and only if it can really be sent.
 *
 * The order is the design:
 *
 *  1. **Claim the period.** One row per subscription per period, behind a unique key, so a second run — or a second
 *     worker — finds it taken. A period already settled is never sent again.
 *  2. **Is there a mail provider?** If not, the period is recorded `awaiting_credentials` and nothing else happens:
 *     no link is minted for an email nobody will receive.
 *  3. **Act as the member who added the recipient** and make the link through `ShareReport` itself. That is the
 *     re-authorisation: if they left, lost `reports.share`, can no longer see the report, or the report stopped being
 *     client-safe, the share is refused, the subscription is halted with the reason, and nothing is sent.
 *  4. **Send**, and record what the provider said. "Accepted" is the provider taking the message, not its arrival.
 *
 * The email carries the link and nothing from the report: no figure, no client name beyond the report's own title.
 * A forwarded email then discloses a link that expires, not a table of somebody's revenue.
 */
final readonly class SendDueReportEmails
{
    /** A period that keeps failing is tried this many times, then left as failed for a person to look at. */
    public const int MAX_ATTEMPTS = 3;

    public function __construct(
        private ReportSubscriptionStore $subscriptions,
        private ReportRepository $reports,
        private ShareReport $share,
        private MailTransport $mail,
        private EmailRenderer $renderer,
        private ActorContextBinder $actors,
        private Clock $clock,
        private string $frontendUrl,
    ) {}

    /** @return array{claimed: int, accepted: int, failed: int, awaiting_credentials: int, skipped: int} */
    public function __invoke(int $limit = 500): array
    {
        $now = $this->clock->now();
        $tally = ['claimed' => 0, 'accepted' => 0, 'failed' => 0, 'awaiting_credentials' => 0, 'skipped' => 0];

        foreach ($this->subscriptions->active($limit) as $subscription) {
            $deliveryId = $this->subscriptions->claim(
                $subscription->workspaceId,
                $subscription->id,
                $subscription->cadence->periodKey($now),
                self::MAX_ATTEMPTS,
                $now,
            );

            if ($deliveryId === null) {
                continue;
            }

            $tally['claimed']++;

            if (! $this->mail->isConfigured()) {
                $this->subscriptions->settle($deliveryId, EmailDeliveryState::AwaitingCredentials, $this->mail->missing(), null, null);
                $tally['awaiting_credentials']++;

                continue;
            }

            if ($subscription->createdBy === null) {
                $this->halt($subscription, $deliveryId, 'The member who added this recipient no longer exists, so nobody\'s access can be checked.');
                $tally['skipped']++;

                continue;
            }

            try {
                $this->actors->bind(new UserId($subscription->createdBy), new WorkspaceId($subscription->workspaceId));

                $created = ($this->share)($subscription->reportId, $subscription->cadence->linkDays(), null, $subscription->recipientEmail);
                $report = $this->reports->find($subscription->workspaceId, null, $subscription->reportId);
            } catch (Throwable $refused) {
                $this->halt($subscription, $deliveryId, 'The member who added this recipient can no longer share this report: '.$refused->getMessage());
                $tally['skipped']++;

                continue;
            } finally {
                $this->actors->clear();
            }

            $unsubscribe = bin2hex(random_bytes(32));
            $this->subscriptions->rotateUnsubscribeToken($subscription->id, hash('sha256', $unsubscribe));

            $base = rtrim($this->frontendUrl, '/');
            $unsubscribeUrl = $base.'/report-emails/unsubscribe/'.$unsubscribe;

            $email = $this->renderer->render(EmailKind::ReportShared, $subscription->locale === 'en' ? 'en' : 'ar', $base.'/shared-reports/'.$created['token'], [
                'name' => $subscription->recipientName,
                'subject' => $report?->definition->name ?? '',
                'detail' => ($subscription->locale === 'ar' ? 'لإيقاف هذه الرسائل: ' : 'To stop these emails: ').$unsubscribeUrl,
            ]);

            $result = $this->mail->send($subscription->recipientEmail, $subscription->recipientName, $email, [
                'List-Unsubscribe' => '<'.$unsubscribeUrl.'>',
            ]);

            if ($result->accepted) {
                $this->subscriptions->settle($deliveryId, EmailDeliveryState::ProviderAccepted, 'Accepted by the mail provider. Arrival in the inbox is not confirmed.', $result->messageId, $created['share']->id);
                $tally['accepted']++;
            } else {
                $this->subscriptions->settle($deliveryId, EmailDeliveryState::Failed, $result->detail ?? 'The mail provider refused the message.', null, $created['share']->id);
                $tally['failed']++;
            }
        }

        return $tally;
    }

    private function halt(ReportSubscription $subscription, string $deliveryId, string $reason): void
    {
        $this->subscriptions->settle($deliveryId, EmailDeliveryState::Skipped, $reason, null, null);
        $this->subscriptions->setStatus($subscription->id, 'halted', $reason);
    }
}
