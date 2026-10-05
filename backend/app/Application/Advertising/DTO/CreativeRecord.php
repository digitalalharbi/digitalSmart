<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\CreativeFormat;

final readonly class CreativeRecord
{
    /**
     * @param  list<CreativeAssetRecord>  $assets
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $externalId,
        public string $name,
        public CreativeFormat $format = CreativeFormat::Unknown,
        public ?string $title = null,
        public ?string $body = null,
        public ?string $callToAction = null,
        public ?string $destinationUrl = null,
        public ?string $thumbnailUrl = null,
        public ?string $previewUrl = null,
        public array $assets = [],
        public array $metadata = [],
    ) {}
}
