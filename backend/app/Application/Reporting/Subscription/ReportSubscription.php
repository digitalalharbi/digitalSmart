<?php

declare(strict_types=1);

namespace App\Application\Reporting\Subscription;

use App\Domain\Reporting\EmailCadence;
use DateTimeImmutable;

/** One recipient of one report's periodic email. */
final readonly class ReportSubscription
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $reportId,
        public string $recipientEmail,
        public ?string $recipientName,
        public string $locale,
        public EmailCadence $cadence,
        /** active · paused · unsubscribed · halted */
        public string $status,
        public string $consentNote,
        public DateTimeImmutable $consentedAt,
        public ?string $haltedReason,
        public ?string $createdBy,
        public ?string $createdByName = null,
    ) {}
}
