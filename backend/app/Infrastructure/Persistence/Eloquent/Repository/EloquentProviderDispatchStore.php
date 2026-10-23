<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\ProviderDispatchStore;
use App\Application\Tracking\DTO\DispatchContext;
use App\Domain\Tracking\Dispatch\SkipReason;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final readonly class EloquentProviderDispatchStore implements ProviderDispatchStore
{
    public function open(DispatchContext $context, array $mapping, string $channel, ?SkipReason $skip): ?string
    {
        $id = (string) Str::uuid7();
        $now = now();

        $inserted = DB::table('provider_event_dispatches')->insertOrIgnore([
            'id' => $id, 'workspace_id' => $context->workspaceId, 'project_id' => $context->projectId, 'mapping_id' => $mapping['id'],
            'event' => $context->event->value, 'provider' => $mapping['provider'], 'provider_event' => $mapping['provider_event'],
            'event_id' => mb_substr($context->eventId, 0, 64), 'channel' => $channel,
            'status' => $skip === null ? 'pending' : 'skipped', 'skip_reason' => $skip?->value,
            'click_ids' => json_encode($context->clickIds, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
            'occurred_at' => $context->occurredAt->format('Y-m-d H:i:sP'), 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $inserted > 0 ? $id : null;
    }

    public function find(string $id): ?array
    {
        $r = DB::table('provider_event_dispatches')->where('id', $id)->first();

        if (! $r instanceof stdClass) {
            return null;
        }

        return [
            'id' => (string) $r->id, 'workspace_id' => (string) $r->workspace_id, 'project_id' => (string) $r->project_id,
            'provider' => (string) $r->provider, 'provider_event' => (string) $r->provider_event, 'event' => (string) $r->event,
            'event_id' => (string) $r->event_id, 'channel' => (string) $r->channel, 'status' => (string) $r->status,
            'attempts' => (int) $r->attempts, 'occurred_at' => (string) $r->occurred_at, 'click_ids' => $this->strings($r->click_ids),
        ];
    }

    public function markSent(string $id, string $sourceState, ?string $providerRef, ?bool $providerReceived, ?bool $deduplicated): void
    {
        DB::table('provider_event_dispatches')->where('id', $id)->update([
            'status' => 'sent', 'source_state' => $sourceState, 'provider_ref' => $providerRef, 'provider_received' => $providerReceived,
            'deduplicated' => $deduplicated, 'server_sent_at' => now(), 'error_category' => null, 'updated_at' => now(),
        ]);
    }

    public function markFailed(string $id, string $category): void
    {
        DB::table('provider_event_dispatches')->where('id', $id)->update(['status' => 'failed', 'error_category' => $category, 'updated_at' => now()]);
    }

    public function markUnknown(string $id): void
    {
        DB::table('provider_event_dispatches')->where('id', $id)->update(['status' => 'unknown', 'updated_at' => now()]);
    }

    public function markSkipped(string $id, SkipReason $reason): void
    {
        DB::table('provider_event_dispatches')->where('id', $id)->update(['status' => 'skipped', 'skip_reason' => $reason->value, 'updated_at' => now()]);
    }

    public function attempt(string $id): void
    {
        DB::table('provider_event_dispatches')->where('id', $id)->increment('attempts');
    }

    public function resetForRetry(string $workspaceId, string $id): bool
    {
        return DB::table('provider_event_dispatches')->where('workspace_id', $workspaceId)->where('id', $id)->whereIn('status', ['failed', 'unknown'])
            ->update(['status' => 'pending', 'error_category' => null, 'updated_at' => now()]) > 0;
    }

    public function list(string $workspaceId, string $projectId, int $limit): array
    {
        $out = [];

        foreach (DB::table('provider_event_dispatches')->where('workspace_id', $workspaceId)->where('project_id', $projectId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get() as $r) {
            $out[] = [
                'id' => (string) $r->id, 'event' => (string) $r->event, 'provider' => (string) $r->provider, 'provider_event' => (string) $r->provider_event,
                'event_id' => (string) $r->event_id, 'channel' => (string) $r->channel, 'status' => (string) $r->status,
                'skip_reason' => $r->skip_reason, 'source_state' => $r->source_state, 'attempts' => (int) $r->attempts,
                'error_category' => $r->error_category,
                'occurred_at' => (new DateTimeImmutable((string) $r->occurred_at))->format(DATE_ATOM),
                'server_sent_at' => $r->server_sent_at === null ? null : (new DateTimeImmutable((string) $r->server_sent_at))->format(DATE_ATOM),
                /* Null is "no provider said so" — never inferred from our own sending. */
                'provider_received' => $r->provider_received === null ? null : (bool) $r->provider_received,
                'deduplicated' => $r->deduplicated === null ? null : (bool) $r->deduplicated,
            ];
        }

        return $out;
    }

    public function visitOfLead(string $workspaceId, string $leadId): ?array
    {
        $projectId = DB::table('leads')->where('workspace_id', $workspaceId)->where('id', $leadId)->value('project_id');

        if (! is_string($projectId)) {
            return null;
        }

        $s = DB::table('form_submissions')->where('workspace_id', $workspaceId)->where('lead_id', $leadId)->orderBy('submitted_at')->first();

        return [
            'project_id' => $projectId,
            'visit' => $s instanceof stdClass
                ? ['consent' => (string) $s->consent_state, 'traffic_class' => (string) $s->traffic_class, 'click_ids' => $this->strings($s->click_ids)]
                : null,
        ];
    }

    /** @return array<string, string> */
    private function strings(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : [];
        $out = [];

        foreach (is_array($decoded) ? $decoded : [] as $k => $v) {
            if (is_scalar($v)) {
                $out[(string) $k] = (string) $v;
            }
        }

        return $out;
    }
}
