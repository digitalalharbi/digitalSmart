<?php

declare(strict_types=1);

namespace App\Application\Tracking\DTO;

use App\Domain\Tracking\LinkStatus;
use DateTimeImmutable;

/** What the public redirect needs to know about a link, and nothing it should not repeat. */
final readonly class LinkTarget
{
    /**
     * @param  array<string, string>  $utm
     * @param  array<string, string>  $customParams
     */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $projectId,
        public ?string $platform,
        public string $channel,
        public string $destinationUrl,
        public array $utm,
        public array $customParams,
        public LinkStatus $status,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
