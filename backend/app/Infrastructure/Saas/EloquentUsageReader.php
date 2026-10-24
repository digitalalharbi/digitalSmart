<?php

declare(strict_types=1);

namespace App\Infrastructure\Saas;

use App\Application\Saas\Contract\UsageReader;
use App\Domain\Saas\Limit;
use App\Domain\Saas\SeatType;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Usage, measured from what is there.
 *
 * Counts come from the tables that already hold the things; monthly figures from the tables that already record the events
 * (tracking events, form submissions, rule executions, exports). Only AI requests, which nothing else records, are counted in
 * `usage_meters`, once per request id. Campaign launches cannot happen yet and are reported as not measured, not as zero.
 */
final readonly class EloquentUsageReader implements UsageReader
{
    /** The workspace's own agent member is not a person and takes no seat. */
    private const string AGENT_EMAILS = '%@agents.digitalsmart.invalid';

    public function used(string $workspaceId, Limit $limit, DateTimeImmutable $now): ?int
    {
        $from = $now->setTimezone(new DateTimeZone('UTC'))->modify('first day of this month')->setTime(0, 0)->format('Y-m-d H:i:sP');

        return match ($limit) {
            Limit::InternalUsers => $this->seats($workspaceId, [SeatType::Full, SeatType::Limited]),
            Limit::ClientUsers => $this->seats($workspaceId, [SeatType::Client, SeatType::Guest]),
            Limit::Clients => DB::table('clients')->where('workspace_id', $workspaceId)->whereNull('deleted_at')->count(),
            Limit::Projects => DB::table('projects')->where('workspace_id', $workspaceId)->whereNull('deleted_at')->count(),
            Limit::ProviderConnections => DB::table('provider_connections')->where('workspace_id', $workspaceId)->count(),
            Limit::ExternalAccounts => DB::table('external_accounts')->where('workspace_id', $workspaceId)->count(),
            Limit::TrackingEventsMonthly => DB::table('tracking_events')->where('workspace_id', $workspaceId)->where('occurred_at', '>=', $from)->count(),
            Limit::FormSubmissionsMonthly => DB::table('form_submissions')->where('workspace_id', $workspaceId)->where('submitted_at', '>=', $from)->count(),
            Limit::AutomationRunsMonthly => DB::table('automation_rule_executions')->where('workspace_id', $workspaceId)->where('created_at', '>=', $from)->count(),
            Limit::ExportsMonthly => DB::table('export_jobs')->where('workspace_id', $workspaceId)->where('requested_at', '>=', $from)->count(),
            Limit::AiRequestsMonthly => (int) (DB::table('usage_meters')->where('workspace_id', $workspaceId)->where('meter', $limit->value)->where('period', substr($from, 0, 7))->value('value') ?? 0),
            Limit::StorageBytes => (int) DB::table('client_request_attachments')->where('workspace_id', $workspaceId)->sum('byte_size')
                + (int) DB::table('export_jobs')->where('workspace_id', $workspaceId)->whereNotNull('byte_size')->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->sum('byte_size'),
            Limit::CampaignLaunchesMonthly => null,
        };
    }

    public function record(string $workspaceId, Limit $limit, string $eventKey, DateTimeImmutable $at): bool
    {
        $period = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m');

        return DB::transaction(function () use ($workspaceId, $limit, $eventKey, $at, $period): bool {
            $inserted = DB::table('usage_events')->insertOrIgnore([
                'workspace_id' => $workspaceId,
                'meter' => $limit->value,
                'event_key' => $eventKey,
                'occurred_at' => $at->format('Y-m-d H:i:sP'),
            ]);

            if ($inserted === 0) {
                return false;
            }

            DB::statement(
                'INSERT INTO usage_meters (workspace_id, meter, period, value, updated_at) VALUES (?, ?, ?, 1, now())
                 ON CONFLICT (workspace_id, meter, period) DO UPDATE SET value = usage_meters.value + 1, updated_at = now()',
                [$workspaceId, $limit->value, $period],
            );

            return true;
        });
    }

    /** @param list<SeatType> $types */
    private function seats(string $workspaceId, array $types): int
    {
        return DB::table('workspace_members as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.workspace_id', $workspaceId)
            ->whereIn('m.status', ['active', 'invited'])
            ->whereIn('m.seat_type', array_map(static fn (SeatType $t): string => $t->value, $types))
            ->where('u.email', 'not like', self::AGENT_EMAILS)
            ->count();
    }
}
