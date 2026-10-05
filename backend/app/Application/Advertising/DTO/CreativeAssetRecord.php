<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

final readonly class CreativeAssetRecord
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $kind,
        public ?string $url = null,
        public ?string $externalId = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $durationMs = null,
        public ?int $fileSizeBytes = null,
        public ?string $mimeType = null,
        public int $position = 0,
        public array $metadata = [],
    ) {}
}
