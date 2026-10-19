<?php

declare(strict_types=1);

namespace App\Application\Agents\Query;

use App\Application\Agents\Contract\AgentReadModel;
use App\Application\Agents\Tool\ToolGateway;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderCategory;
use App\Domain\Integration\ProviderMcp;
use App\Domain\Integration\WriteOperation;
use App\Domain\Integration\WriteSupport;

/**
 * The administrator's view of the machinery: agents' last runs, the routines, the tools and their use, and what
 * each platform can honestly be asked to change. Only workspace administrators see it.
 */
final readonly class GetAgentOverview
{
    public function __construct(
        private AgentReadModel $agents,
        private ToolGateway $tools,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        $workspaceId = $this->actor->workspaceId()->value;
        $runs = $this->agents->recentRuns($workspaceId, 20);
        $counts = $this->agents->toolCounts($workspaceId);
        $connected = $this->agents->connectedProviders($workspaceId);

        $last = static function (string $agent) use ($runs): ?string {
            foreach ($runs as $run) {
                if ($run['agent'] === $agent) {
                    return $run['started_at'];
                }
            }

            return null;
        };

        return [
            'runs' => $runs,
            'tools' => array_map(static fn ($d): array => [
                'name' => $d->name,
                'description' => $d->description,
                'risk' => $d->risk,
                'requires_approval' => $d->requiresApproval,
                'calls_24h' => $counts[$d->name]['calls'] ?? 0,
                'refused_24h' => $counts[$d->name]['refused'] ?? 0,
            ], $this->tools->definitions()),
            'providers' => array_values(array_map(static fn (Provider $p): array => [
                'provider' => $p->value,
                'label' => $p->label(),
                'status_write' => $p->writeSupport(WriteOperation::PauseCampaign)->value,
                'budget_write' => $p->writeSupport(WriteOperation::SetCampaignDailyBudget)->value,
                'connected' => in_array($p->value, $connected, true),
                /* No workspace connects a platform's MCP server yet, so reads go direct and writes go direct or nowhere. */
                'mcp' => ProviderMcp::of($p),
                'read_route' => ProviderMcp::route($p, 'read', false, false)['route'],
                'write_route' => ProviderMcp::route($p, 'write', false, $p->writeSupport(WriteOperation::PauseCampaign) === WriteSupport::Documented)['route'],
            ], array_filter(Provider::cases(), static fn (Provider $p): bool => $p->category() === ProviderCategory::Advertising))),
            'routines' => [
                ['key' => 'sync', 'schedule' => 'hourly', 'last_run' => $this->agents->lastSync($workspaceId)],
                ['key' => 'review', 'schedule' => 'daily_0845', 'last_run' => $last('analyst')],
                ['key' => 'guardian', 'schedule' => 'two_hours', 'last_run' => $last('finance')],
                ['key' => 'project_ops', 'schedule' => 'daily_0830', 'last_run' => $last('operations')],
                ['key' => 'lead_review', 'schedule' => 'daily_1000', 'last_run' => $last('leads')],
                ['key' => 'creative_review', 'schedule' => 'sun_wed_0900', 'last_run' => $last('creative')],
                ['key' => 'weekly_briefs', 'schedule' => 'thu_1400', 'last_run' => $last('reports')],
                ['key' => 'digests', 'schedule' => 'daily', 'last_run' => null],
                ['key' => 'reports', 'schedule' => 'hourly', 'last_run' => null],
            ],
        ];
    }
}
