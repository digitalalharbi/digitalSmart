<?php

declare(strict_types=1);

namespace App\Infrastructure\Campaign;

use App\Application\Campaign\Contract\LaunchFacts;
use App\Application\Campaign\DTO\AccountFacts;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final readonly class EloquentLaunchFacts implements LaunchFacts
{
    public function __construct(private ProviderConnectionRepository $connections) {}

    public function account(string $workspaceId, string $projectId, string $externalAccountId): ?AccountFacts
    {
        if (! Str::isUuid($externalAccountId)) {
            return null;
        }

        $row = DB::table('external_accounts')->where('workspace_id', $workspaceId)->where('id', $externalAccountId)
            ->first(['id', 'provider', 'external_id', 'name', 'currency', 'timezone', 'status', 'provider_connection_id']);

        if (! $row instanceof stdClass) {
            return null;
        }

        $connection = $this->connections->findInWorkspace(new WorkspaceId($workspaceId), new ProviderConnectionId((string) $row->provider_connection_id));
        $provider = Provider::from((string) $row->provider);

        return new AccountFacts(
            id: (string) $row->id,
            provider: $provider->value,
            name: (string) $row->name,
            platformAccountId: (string) $row->external_id,
            currency: $row->currency === null ? null : (string) $row->currency,
            timezone: $row->timezone === null ? null : (string) $row->timezone,
            status: (string) $row->status,
            boundToProject: DB::table('project_integration_bindings')->where('workspace_id', $workspaceId)
                ->where('project_id', $projectId)->where('external_account_id', $externalAccountId)->exists(),
            connectionStatus: $connection instanceof ProviderConnection ? $connection->status()->value : 'not_configured',
            /* Only whether the credentials are the sandbox token; no value leaves the connection. */
            sandbox: $connection instanceof ProviderConnection && $connection->credentials()->isSandbox($provider->requiredCredentialKeys()),
        );
    }

    public function trackingLink(string $workspaceId, string $linkId): ?array
    {
        $row = Str::isUuid($linkId) ? DB::table('tracking_links')->where('workspace_id', $workspaceId)->where('id', $linkId)->first(['status', 'expires_at']) : null;

        return $row instanceof stdClass ? ['status' => (string) $row->status, 'expires_at' => $row->expires_at === null ? null : (string) $row->expires_at] : null;
    }

    public function creatives(string $workspaceId, array $ids): array
    {
        $ids = array_values(array_filter($ids, Str::isUuid(...)));

        if ($ids === []) {
            return [];
        }

        $versions = DB::table('creative_versions')->where('workspace_id', $workspaceId)->whereIn('creative_request_id', $ids)
            ->groupBy('creative_request_id')->selectRaw('creative_request_id, count(*) as n')->pluck('n', 'creative_request_id')->all();

        return array_values(array_map(static fn (stdClass $r): array => [
            'id' => (string) $r->id,
            'title' => (string) $r->title,
            'state' => (string) $r->state,
            'provider' => $r->provider === null ? null : (string) $r->provider,
            'versions' => (int) ($versions[(string) $r->id] ?? 0),
        ], array_values(array_filter(
            DB::table('creative_requests')->where('workspace_id', $workspaceId)->whereIn('id', $ids)->get(['id', 'title', 'state', 'provider'])->all(),
            static fn (mixed $r): bool => $r instanceof stdClass,
        ))));
    }

    public function conversionProviders(string $workspaceId, string $projectId): array
    {
        return array_values(array_map('strval', DB::table('conversion_mappings')->where('workspace_id', $workspaceId)
            ->where('project_id', $projectId)->where('enabled', true)->distinct()->pluck('provider')->all()));
    }

    public function workspaceTimezone(string $workspaceId): string
    {
        $timezone = DB::table('workspaces')->where('id', $workspaceId)->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
