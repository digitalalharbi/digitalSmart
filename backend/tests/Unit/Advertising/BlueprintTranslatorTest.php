<?php

declare(strict_types=1);

use App\Domain\Advertising\Blueprint\BlueprintTranslator;
use App\Domain\Advertising\Blueprint\CampaignBlueprint;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Integration\Provider;

/*
| One campaign described once, in each platform's own words, with what each takes differently or not at all — and always paused.
*/

function translatorBlueprint(CampaignObjective $goal = CampaignObjective::Leads): CampaignBlueprint
{
    return new CampaignBlueprint(
        name: 'Ramadan Sale', goal: $goal, dailyBudgetMinor: 100_001, currency: 'SAR',
        startsOn: new DateTimeImmutable('2026-11-01'), endsOn: null, countries: ['SA', 'AE'], ageMin: 25, ageMax: 44, gender: 'all',
        languages: ['ar', 'en'], interests: 'Home décor', headline: 'Up to 40% off this Ramadan', primaryText: 'Shop before Eid.',
        callToAction: 'Shop now', landingUrl: 'https://example.test/ramadan',
        platforms: [Provider::Meta, Provider::GoogleAds, Provider::LinkedIn, Provider::OpenAI],
    );
}

it('says, per platform and per field, what it can and cannot take', function (): void {
    $b = translatorBlueprint();
    $meta = BlueprintTranslator::translate($b, Provider::Meta);
    $google = BlueprintTranslator::translate($b, Provider::GoogleAds);
    $linkedin = BlueprintTranslator::translate($b, Provider::LinkedIn);
    $openai = BlueprintTranslator::translate($b, Provider::OpenAI);
    $field = static fn (array $t, string $f): string => (string) (array_column($t['fields'], 'support', 'field')[$f] ?? '');

    expect($meta['objective'])->toBe('OUTCOME_LEADS')
        ->and($meta['payload']['status'])->toBe('PAUSED')
        ->and($field($meta, 'goal'))->toBe('supported')
        ->and($field($google, 'goal'))->toBe('partial')
        ->and($field($linkedin, 'age'))->toBe('partial')
        ->and($field($meta, 'interests'))->toBe('partial')
        ->and($openai['creates'])->toBe('not_supported')
        ->and($meta['daily_budget_minor'] + $google['daily_budget_minor'] + $linkedin['daily_budget_minor'] + $openai['daily_budget_minor'])->toBe(100_001);
});

it('has no objective where a platform has none for the intent', function (): void {
    expect(BlueprintTranslator::translate(translatorBlueprint(CampaignObjective::AppPromotion), Provider::LinkedIn)['objective'])->toBeNull();
});
