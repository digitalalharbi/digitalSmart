<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Project\Contract\ReadinessFacts;
use App\Domain\Advertising\CampaignObjective;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EloquentReadinessFacts implements ReadinessFacts
{
    public function project(string $workspaceId, string $projectId): ?array
    {
        $p = DB::table('projects')->where(['workspace_id' => $workspaceId, 'id' => $projectId])->whereNull('deleted_at')
            ->first(['id', 'name', 'client_id', 'status']);

        return $p === null ? null : [
            'id' => (string) $p->id, 'name' => (string) $p->name,
            'client_id' => (string) $p->client_id, 'status' => (string) $p->status,
        ];
    }

    public function approvedBudget(string $workspaceId, string $projectId, string $clientId, string $today): array
    {
        $budgets = DB::table('budgets')->where(['workspace_id' => $workspaceId, 'status' => 'approved'])
            ->where(static function ($q) use ($projectId, $clientId): void {
                $q->where('project_id', $projectId)->orWhere(static fn ($x) => $x->whereNull('project_id')->where('client_id', $clientId));
            });

        return [
            'any' => (clone $budgets)->exists(),
            'covers_today' => (clone $budgets)->where('period_end', '>=', $today)->where('period_start', '<=', $today)->exists(),
        ];
    }

    public function boundAccounts(string $workspaceId, string $projectId): array
    {
        $accounts = DB::table('project_integration_bindings as b')->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->where(['b.workspace_id' => $workspaceId, 'b.project_id' => $projectId]);

        return [
            'bound' => (clone $accounts)->count(),
            'usable' => (clone $accounts)->where('a.status', 'active')->where('b.sync_enabled', true)->count(),
        ];
    }

    public function creative(string $workspaceId, string $projectId, string $clientId): array
    {
        $requests = DB::table('creative_requests')->where('workspace_id', $workspaceId)->where('client_id', $clientId)
            ->where(static fn ($q) => $q->where('project_id', $projectId)->orWhereNull('project_id'))
            ->where('state', '!=', 'cancelled');

        return [
            'briefed' => (clone $requests)->exists(),
            'approved' => (clone $requests)->where('state', 'approved')->exists(),
        ];
    }

    public function dataQuality(string $workspaceId, string $projectId): array
    {
        /** @var object{accounts: int|string|null, missing_currency: int|string|null, missing_timezone: int|string|null, timezones: int|string|null}|null $row */
        $row = DB::table('project_integration_bindings as b')
            ->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->where(['b.workspace_id' => $workspaceId, 'b.project_id' => $projectId])
            ->selectRaw(
                'count(*) as accounts, '
                .'count(*) filter (where a.currency is null) as missing_currency, '
                .'count(*) filter (where a.timezone is null) as missing_timezone, '
                .'count(distinct a.timezone) as timezones'
            )
            ->first();

        /*
         * Unplaced: the campaign carries no project of its own, and its account is bound to this project
         * *and* to at least one other — so nobody can say whose it is without being told.
         */
        $unplaced = DB::table('campaigns as c')
            ->where('c.workspace_id', $workspaceId)
            ->whereNull('c.project_id')
            ->whereExists(static function ($q) use ($projectId): void {
                $q->selectRaw('1')->from('project_integration_bindings as mine')
                    ->whereColumn('mine.external_account_id', 'c.external_account_id')
                    ->where('mine.project_id', $projectId);
            })
            ->whereExists(static function ($q) use ($projectId): void {
                $q->selectRaw('1')->from('project_integration_bindings as other')
                    ->whereColumn('other.external_account_id', 'c.external_account_id')
                    ->where('other.project_id', '<>', $projectId);
            })
            ->count();

        return [
            'accounts' => (int) ($row->accounts ?? 0),
            'missing_currency' => (int) ($row->missing_currency ?? 0),
            'missing_timezone' => (int) ($row->missing_timezone ?? 0),
            'timezones' => (int) ($row->timezones ?? 0),
            'unplaced' => $unplaced,
        ];
    }

    public function campaign(string $workspaceId, string $campaignId, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        $c = DB::table('campaigns as c')
            ->join('external_accounts as a', 'a.id', '=', 'c.external_account_id')
            ->where('c.workspace_id', $workspaceId)->where('c.id', $campaignId)
            ->first(['c.id', 'c.objective', 'c.external_account_id', 'a.status as account_status']);

        if ($c === null) {
            return null;
        }

        /* The result column comes from the domain's own whitelist, never from the request. */
        $column = (CampaignObjective::tryFrom((string) $c->objective) ?? CampaignObjective::Other)->resultColumn();

        /** @var object{spend: int|string|null, results: int|string|null, days: int|string|null} $m */
        $m = DB::table('daily_metrics')
            ->where('workspace_id', $workspaceId)->where('entity_type', 'campaign')->where('campaign_id', $campaignId)
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->selectRaw("sum(spend_minor) as spend, sum({$column}) as results, count(distinct date) as days")
            ->first();

        $enabled = DB::table('project_integration_bindings')
            ->where('workspace_id', $workspaceId)->where('external_account_id', $c->external_account_id)
            ->where('sync_enabled', true)->exists();

        return [
            'account_status' => (string) $c->account_status,
            'sync_enabled' => $enabled,
            'spend_minor' => $m->spend === null ? null : (int) $m->spend,
            'results' => $m->results === null ? null : (int) $m->results,
            'reported_days' => (int) ($m->days ?? 0),
            'objective' => (string) $c->objective,
        ];
    }

    public function stuckApprovals(string $workspaceId, string $projectId, DateTimeImmutable $olderThan): int
    {
        return DB::table('approval_requests')
            ->where(['workspace_id' => $workspaceId, 'project_id' => $projectId, 'state' => 'pending'])
            ->where('created_at', '<', $olderThan)->count();
    }
}
