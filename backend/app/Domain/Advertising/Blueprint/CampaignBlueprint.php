<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Blueprint;

use App\Domain\Advertising\CampaignObjective;
use App\Domain\Integration\Provider;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One campaign described once — goal, money, people, message, platforms — before any platform's vocabulary.
 *
 * The translators turn it into each platform's terms and say, field by field, what they cannot take. Nothing in a
 * blueprint is sent anywhere by itself; it becomes paused drafts and launch requests a person approves.
 */
final readonly class CampaignBlueprint
{
    public const array GOALS = [
        CampaignObjective::Awareness, CampaignObjective::Reach, CampaignObjective::Traffic, CampaignObjective::Engagement,
        CampaignObjective::VideoViews, CampaignObjective::Leads, CampaignObjective::AppPromotion, CampaignObjective::Sales,
    ];

    /**
     * @param  list<string>  $countries  ISO 3166-1 alpha-2.
     * @param  list<string>  $languages  ISO 639-1.
     * @param  list<Provider>  $platforms
     */
    public function __construct(
        public string $name,
        public CampaignObjective $goal,
        public int $dailyBudgetMinor,
        public string $currency,
        public DateTimeImmutable $startsOn,
        public ?DateTimeImmutable $endsOn,
        public array $countries,
        public ?int $ageMin,
        public ?int $ageMax,
        public string $gender,
        public array $languages,
        public ?string $interests,
        public string $headline,
        public string $primaryText,
        public string $callToAction,
        public ?string $landingUrl,
        public array $platforms,
    ) {
        if (! in_array($goal, self::GOALS, true)) {
            throw new InvalidArgumentException('That goal cannot be planned across platforms.');
        }

        if ($dailyBudgetMinor <= 0 || $countries === [] || $platforms === []) {
            throw new InvalidArgumentException('A blueprint needs a daily budget, at least one country and one platform.');
        }

        if ($endsOn !== null && $endsOn < $startsOn) {
            throw new InvalidArgumentException('It cannot end before it starts.');
        }

        if ($ageMin !== null && $ageMax !== null && $ageMin > $ageMax) {
            throw new InvalidArgumentException('The age range is the wrong way round.');
        }
    }

    /** The share of the daily budget each platform gets: even, in minor units, remainder to the first. */
    public function budgetFor(Provider $provider): int
    {
        $count = count($this->platforms);
        $each = intdiv($this->dailyBudgetMinor, $count);

        return $this->platforms[0] === $provider ? $each + $this->dailyBudgetMinor - $each * $count : $each;
    }
}
