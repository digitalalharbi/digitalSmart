<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Launch;

use App\Domain\Advertising\MetricSource;

/** What a platform answered to a create, in this product's words. */
final readonly class CreateOutcome
{
    /** @param list<string> $adIds */
    private function __construct(
        public ExecutionStatus $status,
        public MetricSource $source,
        public ?string $campaignId,
        public ?string $adGroupId,
        public array $adIds,
        public ?string $classification,
        public ?string $message,
    ) {}

    /** @param list<string> $adIds */
    public static function created(MetricSource $source, string $campaignId, string $adGroupId, array $adIds): self
    {
        return new self(ExecutionStatus::Created, $source, $campaignId, $adGroupId, $adIds, null, null);
    }

    public static function failed(MetricSource $source, string $classification, string $message): self
    {
        return new self(ExecutionStatus::Failed, $source, null, null, [], $classification, $message);
    }

    public static function unknown(MetricSource $source, string $message): self
    {
        return new self(ExecutionStatus::Unknown, $source, null, null, [], 'unknown', $message);
    }

    public static function blockedExternal(string $message): self
    {
        return new self(ExecutionStatus::BlockedExternal, MetricSource::Live, null, null, [], 'blocked_external', $message);
    }
}
