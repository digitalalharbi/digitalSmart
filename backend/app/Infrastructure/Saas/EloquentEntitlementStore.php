<?php

declare(strict_types=1);

namespace App\Infrastructure\Saas;

use App\Application\Saas\Contract\EntitlementStore;
use App\Application\Saas\DTO\Entitlements;
use App\Application\Saas\DTO\OverrideRecord;
use App\Domain\Saas\Feature;
use App\Domain\Saas\Limit;
use App\Domain\Saas\PlanCatalogue;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final class EloquentEntitlementStore implements EntitlementStore
{
    /** @var array<string, Entitlements> per-request memo: a request asks about one workspace many times */
    private array $memo = [];

    public function forWorkspace(string $workspaceId): Entitlements
    {
        if (isset($this->memo[$workspaceId])) {
            return $this->memo[$workspaceId];
        }

        $plan = DB::table('workspace_subscriptions as s')
            ->join('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.workspace_id', $workspaceId)
            ->first(['p.id', 'p.code', 'p.name']);

        if (! $plan instanceof stdClass) {
            $plan = DB::table('plans')->where('code', (string) config('digitalsmart.saas.default_plan', PlanCatalogue::AGENCY))->first(['id', 'code', 'name']);
        }

        $features = array_fill_keys(Feature::values(), false);
        $limits = array_fill_keys(Limit::values(), null);
        $featureSource = array_fill_keys(Feature::values(), 'plan');
        $limitSource = array_fill_keys(Limit::values(), 'plan');

        if ($plan instanceof stdClass) {
            foreach (DB::table('plan_features')->where('plan_id', $plan->id)->pluck('feature') as $feature) {
                $features[(string) $feature] = true;
            }

            foreach (DB::table('plan_limits')->where('plan_id', $plan->id)->get(['limit_key', 'value']) as $row) {
                if ($row instanceof stdClass) {
                    $limits[(string) $row->limit_key] = $row->value === null ? null : (int) $row->value;
                }
            }
        }

        foreach ($this->inForce($workspaceId) as $override) {
            if ($override->value === 'inherit') {
                continue;
            }

            if ($override->kind === 'feature' && array_key_exists($override->key, $features)) {
                $features[$override->key] = $override->value === 'on';
                $featureSource[$override->key] = 'override';
            } elseif ($override->kind === 'limit' && array_key_exists($override->key, $limits)) {
                $limits[$override->key] = $override->value === 'unlimited' ? null : (int) $override->value;
                $limitSource[$override->key] = 'override';
            }
        }

        foreach (Feature::cases() as $feature) {
            if ($feature->isEverywhere()) {
                $features[$feature->value] = true;
            }
        }

        return $this->memo[$workspaceId] = new Entitlements(
            planCode: $plan instanceof stdClass ? (string) $plan->code : 'none',
            planName: $plan instanceof stdClass ? (string) $plan->name : 'No plan',
            features: $features,
            limits: $limits,
            featureSource: $featureSource,
            limitSource: $limitSource,
        );
    }

    public function planExists(string $code): bool
    {
        return DB::table('plans')->where('code', $code)->exists();
    }

    public function assignPlan(string $workspaceId, string $planCode, string $assignedBy): void
    {
        $planId = DB::table('plans')->where('code', $planCode)->value('id');

        DB::table('workspace_subscriptions')->updateOrInsert(
            ['workspace_id' => $workspaceId],
            ['plan_id' => $planId, 'assigned_at' => now(), 'assigned_by' => $assignedBy, 'updated_at' => now(), 'created_at' => now()],
        );

        unset($this->memo[$workspaceId]);
    }

    public function override(string $workspaceId, string $kind, string $key, string $value, string $reason, string $changedBy): string
    {
        $before = $this->forWorkspace($workspaceId);
        $previous = $kind === 'feature'
            ? (($before->features[$key] ?? false) ? 'on' : 'off')
            : (($before->limits[$key] ?? null) === null ? 'unlimited' : (string) $before->limits[$key]);

        DB::table('workspace_entitlement_overrides')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'workspace_id' => $workspaceId,
            'kind' => $kind,
            'key' => $key,
            'value' => $value,
            'previous_value' => $previous,
            'reason' => $reason,
            'changed_by' => $changedBy,
            'created_at' => now(),
        ]);

        unset($this->memo[$workspaceId]);

        return $previous;
    }

    public function overrides(string $workspaceId, int $limit = 50): array
    {
        $out = [];

        foreach (DB::table('workspace_entitlement_overrides')->where('workspace_id', $workspaceId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get() as $row) {
            if ($row instanceof stdClass) {
                $out[] = new OverrideRecord(
                    (string) $row->kind,
                    (string) $row->key,
                    (string) $row->value,
                    $row->previous_value === null ? null : (string) $row->previous_value,
                    (string) $row->reason,
                    (string) $row->changed_by,
                    new DateTimeImmutable((string) $row->created_at),
                );
            }
        }

        return $out;
    }

    /** @return list<stdClass> the newest override per key */
    private function inForce(string $workspaceId): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT DISTINCT ON (kind, key) kind, key, value
            FROM workspace_entitlement_overrides
            WHERE workspace_id = ?
            ORDER BY kind, key, created_at DESC, id DESC
            SQL, [$workspaceId]);

        return array_values(array_filter($rows, static fn (mixed $r): bool => $r instanceof stdClass));
    }
}
