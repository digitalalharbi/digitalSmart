<?php

declare(strict_types=1);

namespace App\Application\Attribution\DTO;

use App\Domain\Attribution\AttributionModel;
use App\Domain\Commerce\RevenueSource;
use DateTimeImmutable;

/**
 * One attribution question, fully specified.
 *
 * Both the model and the revenue source are required arguments with no defaults, for the same
 * reason: each is a decision that changes the answer, and a default would make it silently on
 * somebody's behalf. "Which campaign earned this" has a different answer per model, and "how much
 * did it earn" has a different answer per authority.
 */
final readonly class AttributionQuery
{
    /** @param list<string>|null $visibleProjectIds */
    public function __construct(
        public string $workspaceId,
        public AttributionModel $model,
        public RevenueSource $revenueSource,
        public DateTimeImmutable $from,
        public DateTimeImmutable $until,
        public ?array $visibleProjectIds,
        public ?string $clientId = null,
        /**
         * How far back a model may look for an earlier conversion, in days.
         *
         * A modelling choice in its own right, and published as one: a 7-day window and a 90-day
         * window credit different campaigns for the same order, and neither is more correct than the
         * other. It applies only to models that read a person's history.
         */
        public int $lookbackDays = 30,
        public int $limit = 20,
    ) {}
}
