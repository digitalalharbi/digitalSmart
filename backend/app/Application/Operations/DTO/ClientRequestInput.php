<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use App\Domain\Operations\ClientRequestKind;
use App\Domain\Operations\StatedUrgency;
use DateTimeImmutable;

/** What somebody supplies to raise a client request. */
final readonly class ClientRequestInput
{
    public function __construct(
        public string $clientId,
        public ClientRequestKind $kind,
        public string $title,
        public ?string $detail = null,
        public ?string $projectId = null,
        /** What the client said. Defaulted to the mildest value, never to urgent. */
        public StatedUrgency $urgency = StatedUrgency::WheneverYouCan,
        /** When the client would like it. A wish, not a commitment — see `ClientRequestView::promisedAt`. */
        public ?DateTimeImmutable $wantedBy = null,
    ) {}
}
