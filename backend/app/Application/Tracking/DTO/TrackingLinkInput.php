<?php

declare(strict_types=1);

namespace App\Application\Tracking\DTO;

use DateTimeImmutable;

final readonly class TrackingLinkInput
{
    /**
     * @param  array<string, string>  $utm  source, medium, campaign, content, term as typed; blanks are filled from the template
     * @param  array<string, string>  $templatePatterns  utm parameter => pattern, applied to anything left blank
     * @param  array<string, string>  $customParams
     */
    public function __construct(
        public string $projectId,
        public string $name,
        public string $destinationUrl,
        public ?string $platform = null,
        public ?string $externalAccountId = null,
        public ?string $campaign = null,
        public ?string $adGroup = null,
        public ?string $ad = null,
        public ?string $creative = null,
        public string $channel = 'paid',
        public array $utm = [],
        public array $templatePatterns = [],
        public array $customParams = [],
        public ?string $objective = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?string $notes = null,
    ) {}
}
