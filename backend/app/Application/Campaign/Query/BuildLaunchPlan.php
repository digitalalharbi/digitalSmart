<?php

declare(strict_types=1);

namespace App\Application\Campaign\Query;

use App\Application\Campaign\Contract\CreatePlanPayloads;
use App\Application\Campaign\Contract\LaunchFacts;
use App\Application\Campaign\DTO\AccountFacts;
use App\Application\Campaign\DTO\BlueprintView;
use App\Application\Saas\EntitlementGuard;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\Launch\PreflightCheck;
use App\Domain\Advertising\Launch\PreflightState;
use App\Domain\Advertising\Launch\ProviderCreatePlan;
use App\Domain\Integration\Capability\CapabilityKey;
use App\Domain\Integration\Capability\CapabilityState;
use App\Domain\Integration\Capability\ProviderCapabilityRegistry;
use App\Domain\Integration\ConnectionStatus;
use App\Domain\Integration\Provider;
use App\Domain\Saas\Limit;
use DateTimeImmutable;

/**
 * The launch plan: for every ad account in a campaign plan, exactly what would be created there and every check that stands
 * between the plan and creating it. Read-only — it validates without creating, and never creates a campaign to find out whether
 * one could be created.
 *
 * Checks are deterministic and local. A platform's own validate-without-creating call (Meta and Google document one) needs the
 * workspace's real credentials, so with sandbox credentials it is reported as awaiting credentials rather than pretended.
 */
final readonly class BuildLaunchPlan
{
    public function __construct(
        private ReadBlueprints $read,
        private LaunchFacts $facts,
        private CreatePlanPayloads $payloads,
        private EntitlementGuard $entitlements,
        private ActorContext $actor,
        private Clock $clock,
    ) {}

    /**
     * @return array{
     *     blueprint_id: string, version: int, state: PreflightState, checks: list<PreflightCheck>,
     *     providers: list<array{plan: ProviderCreatePlan, checks: list<PreflightCheck>, state: PreflightState}>,
     *     totals: array<string, int>
     * }
     */
    public function __invoke(string $id): array
    {
        $plan = $this->read->get($id)['blueprint'];
        $translations = [];

        foreach ($this->read->translation($id) as $translation) {
            $translations[(string) $translation['provider']] = $translation;
        }

        $workspace = $this->actor->workspaceId()->value;
        $global = $this->globalChecks($plan, $workspace);
        $providers = [];
        $totals = [];

        foreach ($plan->targets as $target) {
            $provider = Provider::from($target['provider']);
            $account = $this->facts->account($workspace, $plan->projectId, $target['external_account_id']);
            $translation = $translations[$provider->value] ?? ['objective' => null, 'daily_budget_minor' => 0, 'fields' => []];
            $createPlan = $this->createPlan($plan, $provider, $target, $account, $translation);
            $checks = $this->providerChecks($plan, $provider, $account, $translation, $workspace);
            $providers[] = ['plan' => $createPlan, 'checks' => $checks, 'state' => PreflightState::worst(array_map(static fn (PreflightCheck $c): PreflightState => $c->state, $checks))];
        }

        /*
         * Totals per currency, never across them. A plan has one currency, but each account spends in its own; adding a SAR budget
         * to an AED one would be a number nobody can pay.
         */
        foreach ($providers as $entry) {
            $currency = $entry['plan']->accountCurrency ?? $entry['plan']->currency;
            $totals[$currency] = ($totals[$currency] ?? 0) + $entry['plan']->budgetMinor;
        }

        $states = array_merge(
            array_map(static fn (PreflightCheck $c): PreflightState => $c->state, $global),
            array_map(static fn (array $p): PreflightState => $p['state'], $providers),
        );

        return [
            'blueprint_id' => $plan->id,
            'version' => $plan->version,
            'state' => PreflightState::worst($states),
            'checks' => $global,
            'providers' => $providers,
            'totals' => $totals,
        ];
    }

    /** @return list<PreflightCheck> */
    private function globalChecks(BlueprintView $plan, string $workspace): array
    {
        $checks = [];
        $allowed = $this->entitlements->entitlements($workspace)->limit(Limit::CampaignLaunchesMonthly);

        $checks[] = $allowed === 0
            ? PreflightCheck::of('plan_quota', PreflightState::Blocked, 'This workspace’s plan includes no campaign launches.', 'Ask Smart Code to move the workspace to a plan with campaign launches.')
            : PreflightCheck::ready('plan_quota', $allowed === null ? 'The plan places no limit on campaign launches.' : sprintf('The plan allows %d campaign launches a month.', $allowed));

        $checks[] = $plan->targets === []
            ? PreflightCheck::of('accounts', PreflightState::Blocked, 'No ad account is chosen.', 'Choose at least one ad account bound to the project.')
            : PreflightCheck::ready('accounts', sprintf('%d ad account(s) chosen.', count($plan->targets)));

        $today = $this->clock->now()->setTime(0, 0);
        $checks[] = $plan->startsAt < $today
            ? PreflightCheck::of('dates', PreflightState::Blocked, 'The start date has passed.', 'Move the start date to today or later.')
            : PreflightCheck::ready('dates', $plan->endsAt === null ? 'Starts on time; no end date.' : 'Starts and ends in the right order, in the future.');

        $destination = $plan->destination();
        $checks[] = match (true) {
            $destination === null => PreflightCheck::of('landing', PreflightState::Blocked, 'There is nowhere for people to land.', 'Choose a tracking link or give a landing page.'),
            ! str_starts_with($destination, 'https://') => PreflightCheck::of('landing', PreflightState::Warning, 'The landing page is not served over HTTPS.', 'Use an https:// address; several platforms refuse plain HTTP.'),
            default => PreflightCheck::ready('landing', 'The landing page is set and served over HTTPS.'),
        };

        if ($plan->trackingLink !== null) {
            $link = $this->facts->trackingLink($workspace, $plan->trackingLink['id']);
            $expired = $link !== null && $link['expires_at'] !== null && new DateTimeImmutable($link['expires_at']) < $this->clock->now();
            $checks[] = ($link === null || $link['status'] !== 'active' || $expired)
                ? PreflightCheck::of('tracking_link', PreflightState::Blocked, 'The tracking link is paused, expired or gone.', 'Reopen the link in Tracking, or choose another.')
                : PreflightCheck::ready('tracking_link', 'The tracking link is active; its UTMs are used as they are.');
        }

        $creatives = $this->facts->creatives($workspace, array_column($plan->creatives, 'id'));
        $withFiles = array_filter($creatives, static fn (array $c): bool => $c['versions'] > 0);
        $unapproved = array_filter($creatives, static fn (array $c): bool => $c['state'] !== 'approved');
        $checks[] = match (true) {
            $creatives === [] => PreflightCheck::of('creatives', PreflightState::Blocked, 'No creative is assigned, so no ad can be made.', 'Assign at least one Creative Hub request.'),
            $withFiles === [] => PreflightCheck::of('creatives', PreflightState::Blocked, 'No assigned creative has a file yet.', 'Upload a version in Creative Hub.'),
            $unapproved !== [] => PreflightCheck::of('creatives', PreflightState::Warning, sprintf('%d assigned creative(s) are not approved yet.', count($unapproved)), 'Approve them in Creative Hub before launching.'),
            default => PreflightCheck::ready('creatives', 'Every assigned creative is approved and has a file.'),
        };

        if (in_array($plan->objective, ['leads', 'sales', 'app_install'], true) && $plan->conversion === null) {
            $checks[] = PreflightCheck::of('conversion', PreflightState::Warning, 'A results objective with no conversion to optimise for.', 'Choose the conversion this campaign should drive.');
        }

        return $checks;
    }

    /**
     * @param  array<string, mixed>  $translation
     * @return list<PreflightCheck>
     */
    private function providerChecks(BlueprintView $plan, Provider $provider, ?AccountFacts $account, array $translation, string $workspace): array
    {
        $name = $provider->label();
        $checks = [];

        if ($account === null || ! $account->boundToProject) {
            return [PreflightCheck::of('binding', PreflightState::Blocked, sprintf('The %s account is no longer bound to this project.', $name), 'Bind it again in Integrations, or remove it from the plan.')];
        }

        $connection = ConnectionStatus::tryFrom($account->connectionStatus);
        $checks[] = match (true) {
            $account->sandbox => PreflightCheck::of('connection', PreflightState::AwaitingCredentials, sprintf('The %s connection holds sandbox credentials.', $name), sprintf('Connect the real %s account; nothing can be created with sandbox credentials.', $name)),
            $connection !== ConnectionStatus::Connected => PreflightCheck::of('connection', PreflightState::Blocked, sprintf('The %s connection is %s.', $name, str_replace('_', ' ', $account->connectionStatus)), 'Reconnect it in Integrations.'),
            /* Real credentials, but a live create has never been carried out here: the platform's write access is unproven. */
            default => PreflightCheck::of('connection', PreflightState::BlockedExternal, sprintf('Creating on %s needs write access no live call has confirmed yet.', $name), (string) (ProviderCapabilityRegistry::profile($provider)['access'] ?? 'Ask the platform for write access.')),
        };

        $checks[] = $account->status === 'active'
            ? PreflightCheck::ready('account', 'The ad account is active.')
            : PreflightCheck::of('account', PreflightState::Blocked, sprintf('The ad account is %s.', $account->status), 'Reactivate it on the platform, or choose another account.');

        $checks[] = ($account->currency === null || $account->currency === $plan->currency)
            ? PreflightCheck::ready('currency', sprintf('The account spends in %s, like the plan.', $plan->currency))
            : PreflightCheck::of('currency', PreflightState::Blocked, sprintf('The account spends in %s; the plan is in %s.', $account->currency, $plan->currency), 'Plan this account separately, in its own currency.');

        $workspaceTimezone = $this->facts->workspaceTimezone($workspace);
        $checks[] = ($account->timezone === null || $account->timezone === $workspaceTimezone)
            ? PreflightCheck::ready('timezone', 'The account keeps the workspace’s time.')
            : PreflightCheck::of('timezone', PreflightState::Warning, sprintf('The account runs on %s; the workspace on %s.', $account->timezone, $workspaceTimezone), 'Dates are applied in the account’s time zone; check the start and end.');

        foreach ([CapabilityKey::CampaignCreate, CapabilityKey::AdGroupCreate, CapabilityKey::AdCreate] as $key) {
            $e = ProviderCapabilityRegistry::evidence($provider, $key);
            $checks[] = $e->state === CapabilityState::Documented
                ? PreflightCheck::ready($key->value, sprintf('%s documents %s.', $name, $key->value))
                : PreflightCheck::of($key->value, PreflightState::Unsupported, sprintf('%s’s documentation does not confirm %s.', $name, $key->value), 'Nothing will be attempted on this platform until it is confirmed.');
        }

        $checks[] = $translation['objective'] === null
            ? PreflightCheck::of('objective', PreflightState::Unsupported, sprintf('%s has no objective for this intent.', $name), 'Choose another objective, or remove this account from the plan.')
            : $this->fieldCheck('objective', $translation, 'goal', sprintf('Created as %s.', (string) $translation['objective']));

        $targeting = $plan->targeting;
        $usesDemographic = isset($targeting['age_min']) || isset($targeting['age_max']) || (($targeting['gender'] ?? 'all') !== 'all');
        $checks[] = $this->capabilityCheck($provider, CapabilityKey::TargetingLocation, 'targeting.location', 'Countries');

        if ($usesDemographic) {
            $checks[] = $this->capabilityCheck($provider, CapabilityKey::TargetingDemographic, 'targeting.demographic', 'Age and gender');
        }

        if (($targeting['interests'] ?? null) !== null && $targeting['interests'] !== '') {
            $checks[] = PreflightCheck::of('targeting.interest', PreflightState::Warning, 'Interests are free text and are never sent as they are.', sprintf('A person matches them to %s’s own interest list before launch.', $name));
        }

        $placementKey = ($plan->placement['mode'] ?? 'automatic') === 'manual' ? CapabilityKey::PlacementManual : CapabilityKey::PlacementAutomatic;
        $checks[] = $this->capabilityCheck($provider, $placementKey, 'placement', 'This placement choice');

        if ($plan->conversion !== null) {
            $optimise = ProviderCapabilityRegistry::evidence($provider, CapabilityKey::ConversionOptimize);
            $mapped = in_array($provider->value, $this->facts->conversionProviders($workspace, $plan->projectId), true);
            $checks[] = match (true) {
                $optimise->state !== CapabilityState::Documented => PreflightCheck::of('conversion', PreflightState::Unsupported, sprintf('%s’s documentation does not confirm conversion optimisation.', $name), 'The campaign would optimise for clicks instead; choose a traffic objective or remove the account.'),
                ! $mapped => PreflightCheck::of('conversion', PreflightState::Warning, sprintf('The project sends no conversions to %s yet.', $name), sprintf('Map the conversion to a %s event in Tracking → Conversions.', $name)),
                default => PreflightCheck::ready('conversion', sprintf('Optimises for %s, which the project sends to %s.', $plan->conversion['name'], $name)),
            };
        }

        $validation = ProviderCapabilityRegistry::evidence($provider, CapabilityKey::ValidationPreflight);
        $checks[] = match (true) {
            $validation->state !== CapabilityState::Documented => PreflightCheck::ready('platform_validation', sprintf('%s documents no validation without creating; these checks are local and deterministic.', $name)),
            $account->sandbox => PreflightCheck::of('platform_validation', PreflightState::AwaitingCredentials, sprintf('%s can validate without creating, with real credentials.', $name), sprintf('Connect the real account to have %s validate the plan.', $name)),
            default => PreflightCheck::ready('platform_validation', sprintf('%s will validate the plan without creating it before anything is sent.', $name)),
        };

        $profile = ProviderCapabilityRegistry::profile($provider);
        $checks[] = PreflightCheck::ready('initial_state', ($profile['delivers_by_default'] ?? false)
            ? sprintf('%s delivers new campaigns unless told otherwise; created with %s.', $name, (string) ($profile['paused'] ?? 'its paused value'))
            : sprintf('Created without delivering: %s.', (string) ($profile['paused'] ?? 'paused')));

        return $checks;
    }

    /** @param array<string, mixed> $translation */
    private function fieldCheck(string $key, array $translation, string $field, string $ready): PreflightCheck
    {
        foreach ((array) ($translation['fields'] ?? []) as $f) {
            if (is_array($f) && ($f['field'] ?? null) === $field && ($f['support'] ?? null) === 'partial') {
                return PreflightCheck::of($key, PreflightState::Warning, (string) ($f['note'] ?? 'Taken differently by this platform.'), 'Read the note; it is what the platform will do.');
            }
        }

        return PreflightCheck::ready($key, $ready);
    }

    private function capabilityCheck(Provider $provider, CapabilityKey $key, string $checkKey, string $what): PreflightCheck
    {
        $e = ProviderCapabilityRegistry::evidence($provider, $key);

        return $e->state === CapabilityState::Documented
            ? PreflightCheck::ready($checkKey, sprintf('%s: documented by %s.', $what, $provider->label()))
            : PreflightCheck::of($checkKey, PreflightState::Warning, sprintf('%s: not confirmed in %s’s documentation.', $what, $provider->label()), 'It will be left out of what is sent until it is confirmed.');
    }

    /**
     * @param  array{external_account_id: string, provider: string, name: string, currency: string|null}  $target
     * @param  array<string, mixed>  $translation
     */
    private function createPlan(BlueprintView $plan, Provider $provider, array $target, ?AccountFacts $account, array $translation): ProviderCreatePlan
    {
        $profile = ProviderCapabilityRegistry::profile($provider);
        $warnings = [];
        $blocked = [];

        foreach ((array) ($translation['fields'] ?? []) as $f) {
            if (! is_array($f)) {
                continue;
            }

            if (($f['support'] ?? null) === 'partial' && is_string($f['note'] ?? null)) {
                $warnings[] = $f['note'];
            }

            if (($f['support'] ?? null) === 'unsupported') {
                $blocked[] = (string) ($f['field'] ?? '');
            }
        }

        /* One campaign and one ad group per account; one ad per assigned creative (at least one). */
        $ads = max(1, count($plan->creatives));
        $objective = isset($translation['objective']) ? (string) $translation['objective'] : null;
        $daily = (int) ($translation['daily_budget_minor'] ?? 0);

        return new ProviderCreatePlan(
            provider: $provider,
            externalAccountId: $target['external_account_id'],
            accountName: $target['name'],
            accountCurrency: $account->currency ?? $target['currency'],
            campaigns: 1,
            adGroups: 1,
            ads: $ads,
            creatives: array_map(static fn (array $c): array => ['id' => $c['id'], 'title' => $c['title']], $plan->creatives),
            budgetType: $plan->budgetType,
            budgetMinor: $plan->budgetType === 'daily' ? $daily : intdiv($plan->budgetMinor, max(1, count($plan->targets))),
            currency: $plan->currency,
            startsAt: $plan->startsAt,
            endsAt: $plan->endsAt,
            objective: $objective,
            conversion: $plan->conversion['name'] ?? null,
            destination: $plan->destination(),
            initialState: (string) ($profile['paused'] ?? 'paused'),
            deliversByDefault: (bool) ($profile['delivers_by_default'] ?? false),
            warnings: $warnings,
            blockedFields: array_values(array_filter($blocked)),
            payload: $objective === null ? [] : $this->payloads->draft($provider, $plan, $target['external_account_id'], ['objective' => $objective, 'daily_budget_minor' => $daily]),
        );
    }
}
