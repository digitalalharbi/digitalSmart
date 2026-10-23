<?php

declare(strict_types=1);

namespace App\Application\Tracking\DTO;

use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\TrafficClass;
use DateTimeImmutable;

final readonly class FormSubmissionRecord
{
    /**
     * @param  array<string, string>  $payload
     * @param  array<string, string>  $utm
     * @param  array<string, string>  $clickIds
     */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $projectId,
        public string $formId,
        public string $eventId,
        public ?string $visitorId,
        public ?string $sessionId,
        public ?string $touchpointId,
        public ?string $trackingLinkId,
        public array $payload,
        public array $utm,
        public array $clickIds,
        public ConsentState $consent,
        public TrafficClass $trafficClass,
        public string $locale,
        public DateTimeImmutable $submittedAt,
    ) {}
}
