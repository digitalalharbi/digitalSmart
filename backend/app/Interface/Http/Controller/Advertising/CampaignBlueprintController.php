<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Advertising;

use App\Application\Advertising\Command\PlanBlueprint;
use App\Domain\Advertising\Blueprint\BlueprintTranslator;
use App\Domain\Advertising\Blueprint\CampaignBlueprint;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Integration\Provider;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Goal · Budget · Audience · Creative · Platforms · Review → preview, or paused drafts with launch requests. */
final class CampaignBlueprintController
{
    private const array PLATFORMS = ['meta', 'google_ads', 'tiktok', 'snapchat', 'linkedin', 'x', 'openai'];

    public function preview(Request $request, PlanBlueprint $plan): JsonResponse
    {
        return new JsonResponse(['data' => $plan->preview($this->blueprint($request))]);
    }

    public function drafts(Request $request, PlanBlueprint $plan): JsonResponse
    {
        $request->validate(['project_id' => ['required', 'uuid']]);

        return new JsonResponse(['data' => $plan->createDrafts($this->blueprint($request), (string) $request->input('project_id'))], 201);
    }

    private function blueprint(Request $request): CampaignBlueprint
    {
        /** @var array<string, mixed> $v */
        $v = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'goal' => ['required', 'in:'.implode(',', BlueprintTranslator::goalNames())],
            'daily_budget_minor' => ['required', 'integer', 'min:100', 'max:100000000000'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'countries' => ['required', 'array', 'min:1', 'max:30'],
            'countries.*' => ['string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'age_min' => ['nullable', 'integer', 'min:13', 'max:65'],
            'age_max' => ['nullable', 'integer', 'min:13', 'max:65'],
            'gender' => ['sometimes', 'in:all,female,male'],
            'languages' => ['sometimes', 'array', 'max:10'],
            'languages.*' => ['string', 'size:2', 'regex:/^[a-z]{2}$/'],
            'interests' => ['nullable', 'string', 'max:300'],
            'headline' => ['required', 'string', 'max:150'],
            'primary_text' => ['required', 'string', 'max:2000'],
            'call_to_action' => ['required', 'string', 'max:40'],
            'landing_url' => ['nullable', 'url:https', 'max:500'],
            'platforms' => ['required', 'array', 'min:1', 'max:7'],
            'platforms.*' => ['in:'.implode(',', self::PLATFORMS)],
        ]);

        try {
            return new CampaignBlueprint(
                name: (string) $v['name'],
                goal: CampaignObjective::from((string) $v['goal']),
                dailyBudgetMinor: (int) $v['daily_budget_minor'],
                currency: (string) $v['currency'],
                startsOn: new DateTimeImmutable((string) $v['starts_on']),
                endsOn: isset($v['ends_on']) ? new DateTimeImmutable((string) $v['ends_on']) : null,
                countries: array_values(array_map('strval', (array) $v['countries'])),
                ageMin: isset($v['age_min']) ? (int) $v['age_min'] : null,
                ageMax: isset($v['age_max']) ? (int) $v['age_max'] : null,
                gender: (string) ($v['gender'] ?? 'all'),
                languages: array_values(array_map('strval', (array) ($v['languages'] ?? []))),
                interests: isset($v['interests']) ? (string) $v['interests'] : null,
                headline: (string) $v['headline'],
                primaryText: (string) $v['primary_text'],
                callToAction: (string) $v['call_to_action'],
                landingUrl: isset($v['landing_url']) ? (string) $v['landing_url'] : null,
                platforms: array_values(array_map(static fn (mixed $p): Provider => Provider::from((string) $p), array_unique((array) $v['platforms']))),
            );
        } catch (InvalidArgumentException $invalid) {
            abort(422, $invalid->getMessage());
        }
    }
}
