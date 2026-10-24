<?php

declare(strict_types=1);

namespace App\Application\Campaign\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Campaign\Command\ManageBlueprint;
use App\Application\Campaign\Contract\BlueprintRepository;
use App\Application\Campaign\DTO\BlueprintView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Advertising\Blueprint\BlueprintTranslator;
use App\Domain\Advertising\Blueprint\CampaignBlueprint;
use App\Domain\Advertising\Blueprint\LaunchObjective;
use App\Domain\Integration\Capability\ProviderCapabilityRegistry;
use App\Domain\Integration\Provider;

/** Campaign plans as the reader may see them: their own projects' plans, or every plan for a reader who sees every project. */
final readonly class ReadBlueprints
{
    public function __construct(
        private BlueprintRepository $blueprints,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return array{rows: list<BlueprintView>, total: int} */
    public function list(?string $status, ?string $projectId, int $page): array
    {
        $this->guard->ensure(Permission::CampaignBlueprintsView);

        return $this->blueprints->list($this->actor->workspaceId()->value, $this->visibility->visibleProjectIds(), $status, $projectId, max(1, $page), 25);
    }

    /** @return array{blueprint: BlueprintView, missing: list<string>} */
    public function get(string $id): array
    {
        $this->guard->ensure(Permission::CampaignBlueprintsView);
        $blueprint = $this->blueprints->find($this->actor->workspaceId()->value, $id, $this->visibility->visibleProjectIds())
            ?? throw ResourceNotFound::of('Campaign plan');

        return ['blueprint' => $blueprint, 'missing' => ManageBlueprint::missing($blueprint)];
    }

    /** @return array{accounts: list<array<string, mixed>>, tracking_links: list<array<string, mixed>>, conversions: list<array<string, mixed>>, creatives: list<array<string, mixed>>} */
    public function options(string $projectId): array
    {
        $this->guard->ensure(Permission::CampaignBlueprintsCreate);
        $visible = $this->visibility->visibleProjectIds();

        if ($visible !== null && ! in_array($projectId, $visible, true)) {
            throw ResourceNotFound::of('Project');
        }

        $this->blueprints->clientOf($this->actor->workspaceId()->value, $projectId) ?? throw ResourceNotFound::of('Project');

        return $this->blueprints->options($this->actor->workspaceId()->value, $projectId);
    }

    /**
     * The saved plan in each targeted platform's own words, field by field, with how that platform takes a campaign that must not
     * deliver. The first step of the launch plan ({@see BuildLaunchPlan}); nothing is created or validated against a platform here.
     *
     * @return list<array<string, mixed>>
     */
    public function translation(string $id): array
    {
        $plan = $this->get($id)['blueprint'];
        $providers = array_values(array_unique(array_map(static fn (array $t): string => $t['provider'], $plan->targets)));

        if ($providers === []) {
            return [];
        }

        /* The translator speaks in daily budgets; a lifetime budget is shown as its average day, and says so in the plan. */
        $days = $plan->endsAt === null ? 1 : max(1, (int) ceil(($plan->endsAt->getTimestamp() - $plan->startsAt->getTimestamp()) / 86_400));
        $daily = $plan->budgetType === 'daily' ? $plan->budgetMinor : max(1, intdiv($plan->budgetMinor, $days));
        $t = $plan->targeting;
        $copy = $plan->adCopy;
        $blueprint = new CampaignBlueprint(
            name: $plan->name,
            goal: LaunchObjective::from($plan->objective)->campaignObjective(),
            dailyBudgetMinor: $daily,
            currency: $plan->currency,
            startsOn: $plan->startsAt,
            endsOn: $plan->endsAt,
            countries: array_values(array_map('strval', (array) ($t['countries'] ?? []))) ?: ['SA'],
            ageMin: isset($t['age_min']) ? (int) $t['age_min'] : null,
            ageMax: isset($t['age_max']) ? (int) $t['age_max'] : null,
            gender: (string) ($t['gender'] ?? 'all'),
            languages: array_values(array_map('strval', (array) ($t['languages'] ?? []))),
            interests: isset($t['interests']) ? (string) $t['interests'] : null,
            headline: (string) ($copy['headline'] ?? ''),
            primaryText: (string) ($copy['primary_text'] ?? ''),
            callToAction: (string) ($copy['call_to_action'] ?? ''),
            landingUrl: $plan->destination(),
            platforms: array_map(static fn (string $p): Provider => Provider::from($p), $providers),
        );

        return array_map(static function (Provider $provider) use ($blueprint): array {
            $profile = ProviderCapabilityRegistry::profile($provider);

            return [
                ...BlueprintTranslator::translate($blueprint, $provider),
                'paused_on_create' => $profile['paused'] ?? null,
                'delivers_by_default' => $profile['delivers_by_default'] ?? false,
                'access' => $profile['access'] ?? null,
            ];
        }, $blueprint->platforms);
    }
}
