<?php

declare(strict_types=1);

namespace App\Application\Agents\Query;

use App\Application\Agents\Contract\AgentReadModel;
use App\Application\Agents\Contract\FindingLog;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The Agents and Routines tabs: each agent as a worker with a job — what it does, when it last ran and how that went,
 * how often its runs succeed, and what it has waiting for a person — and each routine with its schedule, its last
 * result and when it runs next. Readable by anybody who reads performance; counts are inside the reader's projects.
 *
 * The schedules here are the ones `routes/console.php` registers; the next run is computed in each routine's own
 * time zone.
 */
final readonly class GetAgentsStatus
{
    /** agent => [role key, the run log key it writes under, the routines it runs] */
    private const array AGENTS = [
        'analyst' => ['run' => 'analyst', 'routines' => ['review']],
        'campaign' => ['run' => 'analyst', 'routines' => ['review']],
        'finance' => ['run' => 'finance', 'routines' => ['guardian']],
        'creative' => ['run' => 'creative', 'routines' => ['creative_review']],
        'leads' => ['run' => 'leads', 'routines' => ['lead_review']],
        'reports' => ['run' => 'reports', 'routines' => ['weekly_briefs']],
        'operations' => ['run' => 'operations', 'routines' => ['project_ops']],
    ];

    /** routine => [agent run key, schedule kind, at, days, every minutes] */
    private const array ROUTINES = [
        'rules' => ['run' => 'automation', 'every' => 15],
        'review' => ['run' => 'analyst', 'at' => '08:45'],
        'project_ops' => ['run' => 'operations', 'at' => '08:30'],
        'lead_review' => ['run' => 'leads', 'at' => '10:00'],
        'guardian' => ['run' => 'finance', 'every' => 120],
        'creative_review' => ['run' => 'creative', 'at' => '09:00', 'days' => [0, 3]],
        'weekly_briefs' => ['run' => 'reports', 'at' => '14:00', 'days' => [4]],
        'tracking_review' => ['run' => 'tracking', 'at' => '07:30'],
        /* The executive digest: the 1st of each month at 08:00 UTC (11:00 Riyadh), from routes/console.php. */
        'monthly_executive' => ['run' => 'digest', 'at' => '11:00', 'month_day' => 1],
    ];

    private const string ZONE = 'Asia/Riyadh';

    public function __construct(
        private AgentReadModel $agents,
        private FindingLog $findings,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    /** @return array{agents: list<array<string, mixed>>, routines: list<array<string, mixed>>} */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $workspace = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();
        $runs = $this->agents->recentRuns($workspace, 400);
        $proposals = $this->agents->openProposals($workspace, $visible);
        $open = [];

        foreach ($this->findings->open($workspace, $visible, 500) as $finding) {
            $open[$finding['agent']] = ($open[$finding['agent']] ?? 0) + 1;
        }

        $byRun = [];

        foreach ($runs as $run) {
            $byRun[$run['agent']][] = $run;
        }

        $agents = [];

        foreach (self::AGENTS as $agent => $def) {
            $mine = $byRun[$def['run']] ?? [];
            $finished = array_filter($mine, static fn (array $r): bool => $r['status'] !== 'analysing' && $r['status'] !== 'preparing');
            $ok = array_filter($finished, static fn (array $r): bool => $r['status'] !== 'failed');

            $agents[] = [
                'agent' => $agent,
                'status' => $mine === [] ? 'never' : ($mine[0]['status'] === 'failed' ? 'failing' : 'active'),
                'last_run' => $mine[0]['started_at'] ?? null,
                'last_status' => $mine[0]['status'] ?? null,
                'looked_at' => $mine[0]['looked_at'] ?? null,
                'success_rate' => $finished === [] ? null : (int) round(count($ok) / count($finished) * 100),
                'runs' => count($finished),
                'open_recommendations' => ($proposals[$agent] ?? 0) + ($open[$agent] ?? 0),
                'routines' => $def['routines'],
            ];
        }

        $now = $this->clock->now();
        $routines = [];

        foreach (self::ROUTINES as $key => $def) {
            $last = ($byRun[$def['run']] ?? [])[0] ?? null;
            $routines[] = [
                'routine' => $key,
                'agent' => $def['run'],
                'schedule' => isset($def['every']) ? ['every_minutes' => $def['every']] : ['at' => $def['at'], 'days' => $def['days'] ?? null, 'month_day' => $def['month_day'] ?? null, 'timezone' => self::ZONE],
                'last_run' => $last['started_at'] ?? null,
                'last_status' => $last['status'] ?? null,
                'next_run' => $this->next($def, $now)->format(DATE_ATOM),
            ];
        }

        return ['agents' => $agents, 'routines' => $routines];
    }

    /** @param array{every?: int, at?: string, days?: list<int>, month_day?: int} $def */
    private function next(array $def, DateTimeImmutable $now): DateTimeImmutable
    {
        if (isset($def['every'])) {
            $step = $def['every'] * 60;

            return $now->setTimestamp(intdiv($now->getTimestamp(), $step) * $step + $step);
        }

        $local = $now->setTimezone(new DateTimeZone(self::ZONE));
        [$hour, $minute] = array_map('intval', explode(':', $def['at'] ?? '08:00'));

        for ($i = 0; $i <= 31; $i++) {
            $slot = $local->modify(sprintf('+%d days', $i))->setTime($hour, $minute);

            if ($slot > $local
                && (! isset($def['days']) || in_array((int) $slot->format('w'), $def['days'], true))
                && (! isset($def['month_day']) || (int) $slot->format('j') === $def['month_day'])) {
                return $slot;
            }
        }

        return $local->modify('+1 day');
    }
}
