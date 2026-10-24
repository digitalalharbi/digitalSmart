<?php

declare(strict_types=1);

namespace App\Infrastructure\Campaign;

use App\Application\Campaign\Contract\LaunchRepository;
use App\Application\Campaign\DTO\ExecutionRecord;
use App\Domain\Advertising\Launch\ExecutionStatus;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final readonly class EloquentLaunchRepository implements LaunchRepository
{
    public function createLaunch(string $id, string $workspaceId, string $clientId, string $projectId, string $blueprintId, int $version, string $fingerprint, array $snapshot, ?string $requestedBy, ?string $approvedBy, string $executedBy): void
    {
        $blueprint = DB::table('campaign_blueprints')->where('id', $blueprintId)->first(['submitted_by', 'approved_by']);

        DB::table('campaign_launches')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'client_id' => $clientId,
            'project_id' => $projectId,
            'blueprint_id' => $blueprintId,
            'blueprint_version' => $version,
            'fingerprint' => $fingerprint,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'requested_by' => $requestedBy ?? ($blueprint instanceof stdClass ? $blueprint->submitted_by : null),
            'approved_by' => $approvedBy ?? ($blueprint instanceof stdClass ? $blueprint->approved_by : null),
            'executed_by' => $executedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function createExecution(string $id, string $workspaceId, string $launchId, string $provider, string $externalAccountId, string $idempotencyReference, ExecutionStatus $status, ?string $message, ?string $apiReference): void
    {
        DB::table('campaign_launch_executions')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'launch_id' => $launchId,
            'provider' => $provider,
            'external_account_id' => $externalAccountId,
            'idempotency_reference' => $idempotencyReference,
            'status' => $status->value,
            'message' => $message,
            'api_reference' => $apiReference === null ? null : mb_substr($apiReference, 0, 255),
            'failure_classification' => match ($status) {
                ExecutionStatus::Skipped => 'preflight',
                ExecutionStatus::BlockedExternal => 'blocked_external',
                default => null,
            },
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function execution(string $workspaceId, string $id): ?ExecutionRecord
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $row = $this->base($workspaceId)->where('e.id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function executionsForBlueprint(string $workspaceId, string $blueprintId): array
    {
        return array_values(array_map($this->hydrate(...), array_values(array_filter(
            $this->base($workspaceId)->where('l.blueprint_id', $blueprintId)->orderByDesc('l.created_at')->orderByDesc('l.id')->orderBy('e.provider')->get()->all(),
            static fn (mixed $r): bool => $r instanceof stdClass,
        ))));
    }

    public function snapshot(string $workspaceId, string $launchId): array
    {
        $snapshot = DB::table('campaign_launches')->where('workspace_id', $workspaceId)->where('id', $launchId)->value('snapshot');

        return is_string($snapshot) ? (array) json_decode($snapshot, true) : [];
    }

    public function updateExecution(string $workspaceId, string $id, array $fields): void
    {
        DB::table('campaign_launch_executions')->where('workspace_id', $workspaceId)->where('id', $id)->update([...$fields, 'updated_at' => now()]);
    }

    private function base(string $workspaceId): Builder
    {
        return DB::table('campaign_launch_executions as e')
            ->join('campaign_launches as l', 'l.id', '=', 'e.launch_id')
            ->where('e.workspace_id', $workspaceId)
            ->select(['e.*', 'l.blueprint_id', 'l.project_id']);
    }

    private function hydrate(stdClass $r): ExecutionRecord
    {
        $date = static fn (mixed $v): ?DateTimeImmutable => $v === null ? null : new DateTimeImmutable((string) $v);

        return new ExecutionRecord(
            id: (string) $r->id,
            workspaceId: (string) $r->workspace_id,
            launchId: (string) $r->launch_id,
            blueprintId: (string) $r->blueprint_id,
            projectId: (string) $r->project_id,
            provider: (string) $r->provider,
            externalAccountId: (string) $r->external_account_id,
            attempt: (int) $r->attempt,
            idempotencyReference: (string) $r->idempotency_reference,
            status: ExecutionStatus::from((string) $r->status),
            source: $r->source === null ? null : (string) $r->source,
            providerCampaignId: $r->provider_campaign_id === null ? null : (string) $r->provider_campaign_id,
            providerAdGroupId: $r->provider_ad_group_id === null ? null : (string) $r->provider_ad_group_id,
            providerAdIds: array_values(array_map('strval', (array) json_decode((string) $r->provider_ad_ids, true))),
            readback: $r->readback === null ? null : (array) json_decode((string) $r->readback, true),
            failureClassification: $r->failure_classification === null ? null : (string) $r->failure_classification,
            message: $r->message === null ? null : (string) $r->message,
            requestStartedAt: $date($r->request_started_at),
            requestFinishedAt: $date($r->request_finished_at),
        );
    }
}
