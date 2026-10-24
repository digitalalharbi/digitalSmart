<?php

declare(strict_types=1);

namespace App\Infrastructure\Campaign;

use App\Application\Campaign\Contract\CreatePlanPayloads;
use App\Application\Campaign\DTO\BlueprintView;
use App\Domain\Advertising\ValueObject\CurrencyExponent;
use App\Domain\Integration\Provider;

/**
 * A campaign plan in each platform's own field names, as its documentation (read 2026-10-24, `docs/CAMPAIGN_LAUNCH.md`) names
 * them, at every level in the value that does not deliver. These are drafts for a person to read and for PR G's adapters to start
 * from — never sent from here, and not a claim that a platform will accept them unchanged.
 *
 * Every level carries its paused value explicitly, including where a platform would default to paused anyway: TikTok and X
 * deliver by default, and a draft that leaned on a default would be one forgotten field away from spending money.
 */
final readonly class DocumentedPayloadDrafts implements CreatePlanPayloads
{
    public function draft(Provider $provider, BlueprintView $plan, string $externalAccountId, array $translation): array
    {
        $daily = $translation['daily_budget_minor'];
        $micros = $daily * 10 ** (6 - CurrencyExponent::for($plan->currency));
        $major = $daily / 10 ** CurrencyExponent::for($plan->currency);
        $countries = array_values(array_map('strval', (array) ($plan->targeting['countries'] ?? [])));
        $start = $plan->startsAt->format(DATE_ATOM);
        $end = $plan->endsAt?->format(DATE_ATOM);
        $ads = max(1, count($plan->creatives));
        $repeat = static fn (array $ad): array => array_fill(0, $ads, $ad);

        return match ($provider) {
            Provider::Meta => [
                'campaign' => ['name' => $plan->name, 'objective' => $translation['objective'], 'status' => 'PAUSED', 'special_ad_categories' => [], 'daily_budget' => $daily],
                'ad_set' => ['name' => $plan->name, 'status' => 'PAUSED', 'start_time' => $start, 'end_time' => $end, 'targeting' => ['geo_locations' => ['countries' => $countries]]],
                'ads' => $repeat(['status' => 'PAUSED']),
            ],
            Provider::GoogleAds => [
                'campaign_budget' => ['amount_micros' => $micros],
                'campaign' => ['name' => $plan->name, 'status' => 'PAUSED', 'advertising_channel_type' => $translation['objective']],
                'ad_group' => ['name' => $plan->name, 'status' => 'PAUSED'],
                'ad_group_ads' => $repeat(['status' => 'PAUSED']),
            ],
            Provider::TikTok => [
                'campaign' => ['campaign_name' => $plan->name, 'objective_type' => $translation['objective'], 'budget_mode' => 'BUDGET_MODE_DAY', 'budget' => $major, 'operation_status' => 'DISABLE'],
                'ad_group' => ['adgroup_name' => $plan->name, 'operation_status' => 'DISABLE', 'schedule_start_time' => $start, 'schedule_end_time' => $end,
                    'placement_type' => ($plan->placement['mode'] ?? 'automatic') === 'manual' ? 'PLACEMENT_TYPE_NORMAL' : 'PLACEMENT_TYPE_AUTOMATIC'],
                'ads' => $repeat(['operation_status' => 'DISABLE']),
            ],
            Provider::Snapchat => [
                'campaign' => ['name' => $plan->name, 'ad_account_id' => $externalAccountId, 'status' => 'PAUSED', 'start_time' => $start, 'end_time' => $end],
                'ad_squad' => ['name' => $plan->name, 'status' => 'PAUSED', 'daily_budget_micro' => $micros,
                    'targeting' => ['geos' => array_map(static fn (string $c): array => ['country_code' => strtolower($c)], $countries)],
                    'placement_v2' => ['config' => ($plan->placement['mode'] ?? 'automatic') === 'manual' ? 'CUSTOM' : 'AUTOMATIC']],
                'ads' => $repeat(['status' => 'PAUSED']),
            ],
            Provider::LinkedIn => [
                /* A campaign group's non-serving status is not confirmed in the pages read; DRAFT is used and flagged. */
                'campaign_group' => ['name' => $plan->name, 'status' => 'DRAFT', 'note' => 'status not confirmed for campaign groups'],
                'campaign' => ['name' => $plan->name, 'status' => 'PAUSED', 'objectiveType' => $translation['objective'], 'dailyBudget' => ['amount' => (string) $major, 'currencyCode' => $plan->currency]],
                'creatives' => $repeat(['intendedStatus' => 'PAUSED']),
            ],
            Provider::X => [
                'campaign' => ['name' => $plan->name, 'entity_status' => 'PAUSED', 'daily_budget_amount_local_micro' => $micros, 'start_time' => $start, 'end_time' => $end],
                'line_item' => ['name' => $plan->name, 'entity_status' => 'PAUSED', 'objective' => $translation['objective']],
                /* A promoted tweet takes no status on create; it does not deliver while its line item is paused. */
                'promoted_tweets' => $repeat(['note' => 'no status on create; held by the paused line item']),
            ],
            Provider::OpenAI => [
                'campaign' => ['name' => $plan->name, 'status' => 'paused'],
                'ad_group' => ['name' => $plan->name, 'status' => 'paused'],
                'ads' => $repeat(['status' => 'paused']),
            ],
            default => [],
        };
    }
}
