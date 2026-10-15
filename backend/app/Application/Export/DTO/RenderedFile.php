<?php

declare(strict_types=1);

namespace App\Application\Export\DTO;

final readonly class RenderedFile
{
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $bytes,
    ) {}
}
