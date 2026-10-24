<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Launch;

use App\Domain\Advertising\MetricSource;

/** What a platform reported after being asked to switch a campaign on, read back. */
final readonly class ActivationOutcome
{
    /** @param array<string, mixed> $observed */
    public function __construct(
        public ActivationStatus $status,
        public MetricSource $source,
        public array $observed,
        public ?string $message,
    ) {}
}
