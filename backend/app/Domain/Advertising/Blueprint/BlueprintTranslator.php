<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Blueprint;

use App\Domain\Advertising\CampaignObjective;
use App\Domain\Integration\Provider;

/**
 * A blueprint in each platform's own words, with what each platform cannot take.
 *
 * The objective names are each platform's published campaign objectives. The verdicts are deliberately conservative:
 * where a platform takes a field only in a different shape (LinkedIn's fixed age buckets, Google's audience segments
 * instead of free interests), it is `partial` with the reason. Creating the campaign on the platform is not done here
 * and is not claimed: every platform's create call is unverified in this product, so a draft is a request a person
 * approves and then makes, paused.
 */
final class BlueprintTranslator
{
    /** @var array<string, array<string, string|null>> provider → goal → the platform's objective (null: none) */
    private const array OBJECTIVES = [
        'meta' => ['awareness' => 'OUTCOME_AWARENESS', 'reach' => 'OUTCOME_AWARENESS', 'traffic' => 'OUTCOME_TRAFFIC', 'engagement' => 'OUTCOME_ENGAGEMENT', 'video_views' => 'OUTCOME_AWARENESS', 'leads' => 'OUTCOME_LEADS', 'app_promotion' => 'OUTCOME_APP_PROMOTION', 'sales' => 'OUTCOME_SALES'],
        'google_ads' => ['awareness' => 'DISPLAY', 'reach' => 'VIDEO', 'traffic' => 'SEARCH', 'engagement' => 'DEMAND_GEN', 'video_views' => 'VIDEO', 'leads' => 'SEARCH', 'app_promotion' => 'MULTI_CHANNEL', 'sales' => 'PERFORMANCE_MAX'],
        'tiktok' => ['awareness' => 'REACH', 'reach' => 'REACH', 'traffic' => 'TRAFFIC', 'engagement' => 'ENGAGEMENT', 'video_views' => 'VIDEO_VIEWS', 'leads' => 'LEAD_GENERATION', 'app_promotion' => 'APP_PROMOTION', 'sales' => 'WEB_CONVERSIONS'],
        'snapchat' => ['awareness' => 'AWARENESS_AND_ENGAGEMENT', 'reach' => 'AWARENESS_AND_ENGAGEMENT', 'traffic' => 'TRAFFIC', 'engagement' => 'ENGAGEMENT', 'video_views' => 'AWARENESS_AND_ENGAGEMENT', 'leads' => 'LEADS', 'app_promotion' => 'APP_PROMOTION', 'sales' => 'SALES'],
        'linkedin' => ['awareness' => 'BRAND_AWARENESS', 'reach' => 'BRAND_AWARENESS', 'traffic' => 'WEBSITE_VISIT', 'engagement' => 'ENGAGEMENT', 'video_views' => 'VIDEO_VIEW', 'leads' => 'LEAD_GENERATION', 'app_promotion' => null, 'sales' => 'WEBSITE_CONVERSION'],
        'x' => ['awareness' => 'REACH', 'reach' => 'REACH', 'traffic' => 'WEBSITE_CLICKS', 'engagement' => 'ENGAGEMENTS', 'video_views' => 'VIDEO_VIEWS', 'leads' => 'WEBSITE_CLICKS', 'app_promotion' => 'APP_INSTALLS', 'sales' => 'WEBSITE_CONVERSIONS'],
    ];

    /** Where the platform's objective is a near match rather than the goal itself. */
    private const array APPROXIMATE = [
        'meta' => ['reach' => 'Reach is an optimisation inside Awareness.', 'video_views' => 'Video views are an optimisation inside Awareness.'],
        'google_ads' => ['awareness' => 'Display campaign; no single awareness objective.', 'reach' => 'Video reach campaign.', 'engagement' => 'Demand Gen is the closest match.', 'leads' => 'Search campaign with a lead conversion goal.'],
        'snapchat' => ['reach' => 'Reach is an optimisation inside Awareness & Engagement.', 'video_views' => 'Video views are an optimisation inside Awareness & Engagement.'],
        'tiktok' => ['awareness' => 'Reach is TikTok’s awareness objective.'],
        'linkedin' => ['reach' => 'Reach is an optimisation inside Brand awareness.'],
        'x' => ['awareness' => 'Reach is X’s awareness objective.', 'leads' => 'No lead objective; website clicks to a lead form.'],
    ];

    /**
     * @return array{provider: string, objective: string|null, daily_budget_minor: int, currency: string, creates: string, fields: list<array{field: string, support: string, note: string|null}>, payload: array<string, mixed>}
     */
    public static function translate(CampaignBlueprint $b, Provider $provider): array
    {
        $key = $provider->value;
        $goal = $b->goal->value;

        if (! isset(self::OBJECTIVES[$key])) {
            return [
                'provider' => $key,
                'objective' => null,
                'daily_budget_minor' => $b->budgetFor($provider),
                'currency' => $b->currency,
                'creates' => 'not_supported',
                'fields' => [['field' => 'platform', 'support' => FieldSupport::Unsupported->value, 'note' => $provider === Provider::OpenAI ? 'ChatGPT Ads publishes an advertiser API (v1); this product has no translator or adapter for it yet.' : 'No advertising API this product can plan against.']],
                'payload' => [],
            ];
        }

        $objective = self::OBJECTIVES[$key][$goal] ?? null;
        $fields = [];

        $fields[] = self::field('goal', $objective === null ? FieldSupport::Unsupported : (isset(self::APPROXIMATE[$key][$goal]) ? FieldSupport::Partial : FieldSupport::Supported), $objective === null ? 'This platform has no objective for this goal.' : (self::APPROXIMATE[$key][$goal] ?? null));
        $fields[] = self::field('daily_budget', $key === 'google_ads' ? FieldSupport::Partial : FieldSupport::Supported, $key === 'google_ads' ? 'Google may spend up to twice the daily budget on a day, averaging out over the month.' : null);
        $fields[] = self::field('schedule', FieldSupport::Supported, null);
        $fields[] = self::field('countries', FieldSupport::Supported, null);
        $fields[] = self::field('age', match ($key) {
            'linkedin', 'google_ads' => FieldSupport::Partial,
            default => FieldSupport::Supported,
        }, match ($key) {
            'linkedin' => 'Fixed age buckets (18–24, 25–34, 35–54, 55+); the range is rounded to them.',
            'google_ads' => 'Age is a demographic adjustment, not a hard limit, on Search.',
            default => null,
        });
        $fields[] = self::field('gender', $key === 'linkedin' && $b->gender !== 'all' ? FieldSupport::Partial : FieldSupport::Supported, $key === 'linkedin' && $b->gender !== 'all' ? 'Gender targeting is limited in some regions on LinkedIn.' : null);
        $fields[] = self::field('languages', FieldSupport::Supported, null);
        /* Free-text interests are never sent: every platform has its own list, and matching to it is a person's call. */
        $fields[] = self::field('interests', $b->interests === null || $b->interests === '' ? FieldSupport::Supported : FieldSupport::Partial, $b->interests === null || $b->interests === '' ? null : match ($key) {
            'google_ads' => 'Mapped to audience segments by a person; free-text interests are not a Google field.',
            'linkedin' => 'Mapped to member interests or skills by a person.',
            default => 'Matched to the platform’s own interest list by a person; free text is not sent.',
        });
        $fields[] = self::field('headline', $key === 'x' && mb_strlen($b->headline) > 70 ? FieldSupport::Partial : FieldSupport::Supported, $key === 'x' && mb_strlen($b->headline) > 70 ? 'X card headlines are 70 characters; it will be shortened.' : null);
        $fields[] = self::field('primary_text', FieldSupport::Supported, null);
        $fields[] = self::field('call_to_action', FieldSupport::Supported, null);

        return [
            'provider' => $key,
            'objective' => $objective,
            'daily_budget_minor' => $b->budgetFor($provider),
            'currency' => $b->currency,
            /* Creating a campaign is not one of the writes this product has read in any platform's documentation. */
            'creates' => 'unverified',
            'fields' => $fields,
            'payload' => [
                'name' => $b->name,
                'objective' => $objective,
                'status' => 'PAUSED',
                'daily_budget_minor' => $b->budgetFor($provider),
                'currency' => $b->currency,
                'starts_on' => $b->startsOn->format('Y-m-d'),
                'ends_on' => $b->endsOn?->format('Y-m-d'),
                'countries' => $b->countries,
                'age' => [$b->ageMin, $b->ageMax],
                'gender' => $b->gender,
                'languages' => $b->languages,
                'headline' => $b->headline,
                'primary_text' => $b->primaryText,
                'call_to_action' => $b->callToAction,
                'landing_url' => $b->landingUrl,
            ],
        ];
    }

    /** @return list<string> */
    public static function goalNames(): array
    {
        return array_map(static fn (CampaignObjective $o): string => $o->value, CampaignBlueprint::GOALS);
    }

    /** @return array{field: string, support: string, note: string|null} */
    private static function field(string $field, FieldSupport $support, ?string $note): array
    {
        return ['field' => $field, 'support' => $support->value, 'note' => $note];
    }
}
