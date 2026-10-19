<?php

declare(strict_types=1);

namespace App\Application\Automation\Query;

use App\Application\Agents\Contract\AgentReadModel;
use App\Application\Automation\RuleCapability;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Automation\RuleAction;
use App\Domain\Automation\RuleMetric;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderCategory;

/**
 * What a rule may watch and do on each platform — shown in the builder so an action a platform cannot take reads
 * "Not supported" instead of disappearing or failing later.
 *
 * Metrics come from the normalised daily figures every advertising adapter syncs, so all of them are available where
 * that platform is synced; a campaign whose platform did not report a figure is simply not judged on it. Actions
 * follow `Provider::writeSupport()` — only a write read in the platform's own documentation is "supported" — and a bid
 * change is supported nowhere. A platform with no connection yet says `awaiting_credentials`.
 */
final readonly class GetRuleCapabilities
{
    public function __construct(
        private AgentReadModel $agents,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private RuleCapability $capability,
    ) {}

    /** @return list<array{provider: string, label: string, credentials: string, metrics: list<string>, actions: array<string, string>}> supported · awaiting_credentials · unverified · unsupported */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $connected = $this->agents->connectedProviders($this->actor->workspaceId()->value);
        $out = [];

        foreach (Provider::cases() as $provider) {
            if ($provider->category() !== ProviderCategory::Advertising) {
                continue;
            }

            $actions = [];

            foreach (RuleAction::cases() as $action) {
                $actions[$action->value] = $this->capability->of($action, $provider->value, $this->actor->workspaceId()->value);
            }

            $out[] = [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'credentials' => in_array($provider->value, $connected, true) ? 'connected' : 'awaiting_credentials',
                'metrics' => RuleMetric::values(),
                'actions' => $actions,
            ];
        }

        return $out;
    }
}
