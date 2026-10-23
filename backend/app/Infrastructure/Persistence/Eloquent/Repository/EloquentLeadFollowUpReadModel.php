<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Leads\Automation\FollowUpRow;
use App\Application\Leads\Automation\LeadFollowUpReadModel;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Leads awaiting a person, with the two moments the response time is judged by.
 *
 * The first attempt is the lead's first move out of "new" (the same definition the follow-up report uses); the first contact
 * is the moment somebody got through. A merged lead counts nowhere: it is its survivor's.
 */
final readonly class EloquentLeadFollowUpReadModel implements LeadFollowUpReadModel
{
    public function openLeads(string $workspaceId, ?array $visibleProjectIds, string $projectId, int $limit): array
    {
        if ($visibleProjectIds !== null && ! in_array($projectId, $visibleProjectIds, true)) {
            return [];
        }

        $query = $this->base()->where('l.workspace_id', $workspaceId)->where('l.project_id', $projectId)
            ->whereIn('l.stage', ['new', 'contacted', 'qualified']);

        return $this->rows($query->orderByDesc('l.received_at')->orderByDesc('l.id')->limit($limit)->get()->all());
    }

    public function awaitingFirstAttempt(DateTimeImmutable $since): array
    {
        $query = $this->base()->whereNotNull('a.sla_minutes')->where('l.stage', 'new')
            ->whereNull('l.first_contacted_at')->where('l.received_at', '>=', $since->format('Y-m-d H:i:sP'))
            ->whereRaw("not exists (select 1 from lead_events e where e.lead_id = l.id and e.type = 'stage_changed')");

        return $this->rows($query->orderBy('l.received_at')->limit(2000)->get()->all());
    }

    private function base(): Builder
    {
        return DB::table('leads as l')
            ->leftJoin('lead_automation_settings as a', 'a.project_id', '=', 'l.project_id')
            ->leftJoin('users as u', 'u.id', '=', 'l.owner_id')
            ->whereNull('l.merged_into_id')->whereNotNull('l.project_id')
            ->select([
                'l.id', 'l.workspace_id', 'l.project_id', 'l.client_id', 'l.name', 'l.source_detail', 'l.stage', 'l.owner_id',
                'u.name as owner_name', 'l.assignment_reason', 'l.received_at', 'l.first_contacted_at', 'a.sla_minutes',
                DB::raw("(select min(e.occurred_at) from lead_events e where e.lead_id = l.id and e.type = 'stage_changed') as first_attempt_at"),
            ]);
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<FollowUpRow>
     */
    private function rows(array $rows): array
    {
        $out = [];

        foreach ($rows as $r) {
            if (! $r instanceof stdClass) {
                continue;
            }

            $out[] = new FollowUpRow(
                (string) $r->workspace_id, (string) $r->project_id, (string) $r->client_id, (string) $r->id,
                is_string($r->name) ? $r->name : null, is_string($r->source_detail) ? $r->source_detail : null, (string) $r->stage,
                is_string($r->owner_id) ? $r->owner_id : null, is_string($r->owner_name) ? $r->owner_name : null,
                is_string($r->assignment_reason) ? $r->assignment_reason : null,
                new DateTimeImmutable((string) $r->received_at),
                is_string($r->first_attempt_at) ? new DateTimeImmutable($r->first_attempt_at) : null,
                is_string($r->first_contacted_at) ? new DateTimeImmutable($r->first_contacted_at) : null,
                $r->sla_minutes === null ? null : (int) $r->sla_minutes,
            );
        }

        return $out;
    }
}
