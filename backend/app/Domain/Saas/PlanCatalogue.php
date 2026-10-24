<?php

declare(strict_types=1);

namespace App\Domain\Saas;

/**
 * The fixture plans. Names and contents only — **no prices**: pricing belongs to billing, which attaches later through an
 * adapter, and a price in the domain would be a decision nobody has made.
 *
 * A limit of `null` means not bounded by the plan.
 */
final class PlanCatalogue
{
    public const string STARTER = 'starter';

    public const string GROWTH = 'growth';

    public const string AGENCY = 'agency';

    public const string ENTERPRISE = 'enterprise';

    /**
     * @return array<string, array{name: string, features: list<Feature>, limits: array<string, int|null>}>
     */
    public static function plans(): array
    {
        $everywhere = array_values(array_filter(Feature::cases(), static fn (Feature $f): bool => $f->isEverywhere()));

        $starter = [...$everywhere, Feature::CreativeOperations];
        $growth = [...$starter, Feature::TrackingForms, Feature::TrackingFunnels, Feature::Automation, Feature::AdvancedAnalytics, Feature::Commerce, Feature::Crm];
        $agency = [...$growth, Feature::CampaignLaunch, Feature::ProviderWrites, Feature::ClientPortal, Feature::CreativeIntelligence, Feature::Attribution, Feature::Agents, Feature::Ai, Feature::Presentations];
        $enterprise = Feature::cases();

        return [
            self::STARTER => ['name' => 'Starter', 'features' => $starter, 'limits' => self::limits(3, 2, 5, 10, 3, 10, 50_000, 1_000, 0, 0, 20, 1_073_741_824, 0)],
            self::GROWTH => ['name' => 'Growth', 'features' => $growth, 'limits' => self::limits(10, 10, 25, 60, 7, 50, 500_000, 10_000, 2_000, 500, 200, 10_737_418_240, 0)],
            self::AGENCY => ['name' => 'Agency', 'features' => $agency, 'limits' => self::limits(null, null, null, null, null, null, null, null, null, null, null, null, null)],
            self::ENTERPRISE => ['name' => 'Enterprise', 'features' => $enterprise, 'limits' => self::limits(null, null, null, null, null, null, null, null, null, null, null, null, null)],
        ];
    }

    /** @return array<string, int|null> */
    private static function limits(
        ?int $internalUsers, ?int $clientUsers, ?int $clients, ?int $projects, ?int $connections, ?int $accounts,
        ?int $events, ?int $submissions, ?int $automation, ?int $ai, ?int $exports, ?int $storage, ?int $launches,
    ): array {
        return [
            Limit::InternalUsers->value => $internalUsers,
            Limit::ClientUsers->value => $clientUsers,
            Limit::Clients->value => $clients,
            Limit::Projects->value => $projects,
            Limit::ProviderConnections->value => $connections,
            Limit::ExternalAccounts->value => $accounts,
            Limit::TrackingEventsMonthly->value => $events,
            Limit::FormSubmissionsMonthly->value => $submissions,
            Limit::AutomationRunsMonthly->value => $automation,
            Limit::AiRequestsMonthly->value => $ai,
            Limit::ExportsMonthly->value => $exports,
            Limit::StorageBytes->value => $storage,
            Limit::CampaignLaunchesMonthly->value => $launches,
        ];
    }
}
