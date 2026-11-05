<?php

declare(strict_types=1);

namespace App\Application\Reporting\Subscription;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Notifications\Mail\MailTransport;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Reporting\EmailDeliveryState;
use DateTimeImmutable;

/**
 * A report's email recipients, each with its recent deliveries, and whether email can be sent at all.
 *
 * Behind `reports.share`, like the links: the list is addresses of people outside the agency.
 */
final readonly class ListReportSubscriptions
{
    public function __construct(
        private ReportRepository $reports,
        private ReportSubscriptionStore $subscriptions,
        private MailTransport $mail,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /**
     * @return array{mail_configured: bool, mail_missing: ?string, recipients: list<array{subscription: ReportSubscription, deliveries: list<array{id: string, period_key: string, state: EmailDeliveryState, detail: ?string, attempts: int, attempted_at: ?DateTimeImmutable}>}>}
     */
    public function __invoke(string $reportId): array
    {
        $this->guard->ensure(Permission::ReportsShare);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($this->reports->find($workspaceId, $this->visibility->visibleProjectIds(), $reportId) === null) {
            throw ResourceNotFound::of('Report');
        }

        $recipients = [];

        foreach ($this->subscriptions->forReport($workspaceId, $reportId) as $subscription) {
            $recipients[] = [
                'subscription' => $subscription,
                'deliveries' => $this->subscriptions->deliveries($workspaceId, $subscription->id, 6),
            ];
        }

        $configured = $this->mail->isConfigured();

        return ['mail_configured' => $configured, 'mail_missing' => $configured ? null : $this->mail->missing(), 'recipients' => $recipients];
    }
}
