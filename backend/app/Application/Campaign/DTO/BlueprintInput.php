<?php

declare(strict_types=1);

namespace App\Application\Campaign\DTO;

use App\Domain\Advertising\Blueprint\LaunchObjective;
use DateTimeImmutable;

/**
 * What a person says a campaign should be. Every reference is an id of something that already exists — an ad account bound to
 * the project, a tracking link, a conversion definition, Creative Hub requests — and is checked, never trusted.
 */
final readonly class BlueprintInput
{
    /**
     * @param  list<string>  $externalAccountIds
     * @param  list<string>  $creativeRequestIds
     * @param  array{countries?: list<string>, age_min?: int|null, age_max?: int|null, gender?: string, languages?: list<string>, interests?: string|null}  $targeting
     * @param  array{mode?: string, placements?: list<string>}  $placement
     * @param  array{headline?: string|null, primary_text?: string|null, call_to_action?: string|null}  $adCopy
     */
    public function __construct(
        public string $projectId,
        public string $name,
        public LaunchObjective $objective,
        public string $budgetType,
        public int $budgetMinor,
        public string $currency,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public array $externalAccountIds,
        public ?string $trackingLinkId,
        public ?string $landingUrl,
        public ?string $conversionEventId,
        public array $targeting,
        public array $placement,
        public array $adCopy,
        public array $creativeRequestIds,
    ) {}
}
