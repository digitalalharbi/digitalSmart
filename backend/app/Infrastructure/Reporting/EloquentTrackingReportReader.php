<?php

declare(strict_types=1);

namespace App\Infrastructure\Reporting;

use App\Application\Reporting\Contract\TrackingReportReader;
use App\Application\Reporting\DTO\TrackingSection;
use App\Application\Tracking\Contract\TrackingAnalyticsReadModel;
use App\Application\Tracking\Contract\TrackingHealthReadModel;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\Contract\TrackingOperationsReadModel;
use App\Application\Tracking\Service\LinkPerformanceCalculator;
use App\Domain\Advertising\ValueObject\CurrencyExponent;
use App\Domain\Reporting\TrackingView;
use App\Domain\Tracking\Funnel\FunnelCalculator;
use App\Domain\Tracking\Funnel\FunnelCohort;
use App\Domain\Tracking\Funnel\FunnelDefinition;
use App\Domain\Tracking\Funnel\FunnelSource;
use App\Domain\Tracking\Health\TrackingHealthComposer;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The tracking views of a report, for the whole client.
 *
 * What it returns is safe to send a client by construction: counts, rates and states, with a link named by its label and
 * never by its address, token or id. A step no page reported is `null`, not zero.
 */
final readonly class EloquentTrackingReportReader implements TrackingReportReader
{
    public function __construct(
        private TrackingAnalyticsReadModel $analytics,
        private TrackingHealthReadModel $health,
        private TrackingLinkRepository $links,
        private LinkPerformanceCalculator $performance,
        private TrackingOperationsReadModel $operations,
    ) {}

    public function read(string $workspaceId, string $clientId, TrackingView $view, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        return match ($view) {
            TrackingView::Links => $this->links($workspaceId, $clientId, $from, $until),
            TrackingView::Forms => $this->forms($workspaceId, $clientId, $from, $until),
            TrackingView::Funnel => $this->funnel($workspaceId, $clientId, $from, $until),
            TrackingView::FollowUp => $this->followUp($workspaceId, $clientId, $from, $until),
            TrackingView::Health => $this->healthView($workspaceId, $clientId, $until),
            TrackingView::Roi => $this->roi($workspaceId, $clientId, $from, $until),
            TrackingView::CampaignFunnel => $this->campaignFunnel($workspaceId, $clientId, $from, $until),
            TrackingView::LeadQuality => $this->leadQuality($workspaceId, $clientId, $from, $until),
            TrackingView::SalesFunnel => $this->salesFunnel($workspaceId, $clientId, $from, $until),
            TrackingView::Response => $this->response($workspaceId, $clientId, $from, $until),
        };
    }

    /** @return array{section: TrackingSection, caveats: list<string>} */
    private function links(string $workspaceId, string $clientId, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $rows = DB::select(
            "SELECT name, platform, count(session_id) AS clicks, count(session_id) FILTER (WHERE has_landing) AS landing,
                coalesce(sum(subs), 0) AS submissions, count(DISTINCT lead_id) AS leads
             FROM (
                SELECT tl.id, tl.name, tl.platform, s.id AS session_id,
                    EXISTS (SELECT 1 FROM tracking_events e WHERE e.session_id = s.id AND e.name = 'landing_view' AND e.traffic_class = 'valid') AS has_landing,
                    (SELECT count(*) FROM form_submissions f WHERE f.session_id = s.id AND f.traffic_class = 'valid') AS subs,
                    (SELECT f.lead_id FROM form_submissions f WHERE f.session_id = s.id AND f.lead_id IS NOT NULL ORDER BY f.submitted_at LIMIT 1) AS lead_id
                FROM tracking_links tl
                LEFT JOIN tracking_sessions s ON s.tracking_link_id = tl.id AND s.traffic_class = 'valid' AND s.started_at >= ? AND s.started_at < ?
                WHERE tl.workspace_id = ? AND tl.client_id = ? AND tl.status <> 'archived'
             ) t GROUP BY id, name, platform ORDER BY clicks DESC, name LIMIT 50",
            [$from->format('Y-m-d H:i:sP'), $until->format('Y-m-d H:i:sP'), $workspaceId, $clientId],
        );

        $out = [];

        foreach ($rows as $r) {
            $clicks = (int) $r->clicks;
            $landing = (int) $r->landing;
            $out[] = [
                'link' => (string) $r->name,
                'platform' => is_string($r->platform) ? $r->platform : null,
                'clicks' => $clicks,
                /* Reported by the destination page: nothing reported on a link that was clicked is "not reported". */
                'landing_views' => $clicks > 0 && $landing === 0 ? null : $landing,
                'submissions' => (int) $r->submissions,
                'leads' => (int) $r->leads,
            ];
        }

        return [
            'section' => new TrackingSection(TrackingView::Links, [
                ['key' => 'link', 'label' => 'Link'], ['key' => 'platform', 'label' => 'Platform'], ['key' => 'clicks', 'label' => 'Clicks'],
                ['key' => 'landing_views', 'label' => 'Page views'], ['key' => 'submissions', 'label' => 'Form submissions'], ['key' => 'leads', 'label' => 'Leads'],
            ], $out),
            'caveats' => [
                'Only visits believed to be people are counted; likely automated and test traffic is left out.',
                'A page view is reported by the destination page. "Not reported" means the page sent nothing, not that nobody arrived.',
            ],
        ];
    }

    /** @return array{section: TrackingSection, caveats: list<string>} */
    private function forms(string $workspaceId, string $clientId, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $rows = DB::select(
            "SELECT f.name, count(s.id) AS submissions, count(DISTINCT s.lead_id) AS leads
             FROM forms f
             LEFT JOIN form_submissions s ON s.form_id = f.id AND s.traffic_class = 'valid' AND s.submitted_at >= ? AND s.submitted_at < ?
             WHERE f.workspace_id = ? AND f.client_id = ? AND f.status <> 'archived'
             GROUP BY f.id, f.name ORDER BY submissions DESC, f.name LIMIT 50",
            [$from->format('Y-m-d H:i:sP'), $until->format('Y-m-d H:i:sP'), $workspaceId, $clientId],
        );

        return [
            'section' => new TrackingSection(TrackingView::Forms, [
                ['key' => 'form', 'label' => 'Form'], ['key' => 'submissions', 'label' => 'Submissions'], ['key' => 'leads', 'label' => 'Leads made'],
            ], array_values(array_map(static fn (stdClass $r): array => ['form' => (string) $r->name, 'submissions' => (int) $r->submissions, 'leads' => (int) $r->leads], $rows))),
            'caveats' => ['A repeat enquiry from the same person is one lead, so leads can be fewer than submissions.'],
        ];
    }

    /** @return array{section: TrackingSection, caveats: list<string>} */
    private function funnel(string $workspaceId, string $clientId, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $sessions = [];
        $submissions = 0;
        $leads = [];
        $truncated = false;

        foreach ($this->projects($workspaceId, $clientId) as $projectId) {
            $cohort = $this->analytics->cohort($workspaceId, $projectId, null, $from, $until);
            $sessions = [...$sessions, ...$cohort->sessions];
            $submissions += $cohort->submissions;
            $leads += $cohort->leads;
            $truncated = $truncated || $cohort->truncated;
        }

        $result = FunnelCalculator::compute(FunnelDefinition::standard(), new FunnelCohort($sessions, $submissions, $leads, $truncated));
        $rows = [];

        foreach ($result['stages'] as $stage) {
            $rows[] = [
                'step' => $this->label((string) $stage['source']),
                'count' => $stage['count'],
                'rate' => $stage['conversion_from_previous'] === null ? null : round((float) $stage['conversion_from_previous'] * 100, 1),
            ];
        }

        return [
            'section' => new TrackingSection(TrackingView::Funnel, [
                ['key' => 'step', 'label' => 'Step'], ['key' => 'count', 'label' => 'Count'], ['key' => 'rate', 'label' => 'From the previous step (%)'],
            ], $rows),
            'caveats' => array_values(array_filter([
                'Every rate divides by the step before it. A step the destination page never reported, or a rate that would exceed 100%, is shown as not reported.',
                $result['overall_conversion'] === null ? null : sprintf('Overall, %.1f%% of visits reached the last step.', $result['overall_conversion'] * 100),
                $truncated ? 'This period has more visits than can be read at once; the figures cover the earliest.' : null,
            ])),
        ];
    }

    /** @return array{section: TrackingSection, caveats: list<string>} */
    private function followUp(string $workspaceId, string $clientId, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $sum = ['leads' => 0, 'contacted' => 0, 'qualified' => 0, 'uncontacted' => 0, 'overdue_uncontacted' => 0, 'attempted' => 0, 'within' => 0.0];
        $medians = ['first' => [], 'attempt' => []];

        foreach ($this->projects($workspaceId, $clientId) as $projectId) {
            $r = $this->analytics->followUp($workspaceId, $projectId, $from, $until, 60);
            $leads = (int) $r['leads'];

            foreach (['leads', 'contacted', 'qualified', 'uncontacted', 'overdue_uncontacted', 'attempted'] as $k) {
                $sum[$k] += (int) $r[$k];
            }

            $sum['within'] += $r['sla_compliance'] === null ? 0.0 : (float) $r['sla_compliance'] * $leads;

            if ($r['median_seconds_to_first_contact'] !== null) {
                $medians['first'][] = (int) $r['median_seconds_to_first_contact'];
            }

            if ($r['median_seconds_to_first_attempt'] !== null) {
                $medians['attempt'][] = (int) $r['median_seconds_to_first_attempt'];
            }
        }

        $leads = $sum['leads'];
        $median = static function (array $v): ?float {
            if ($v === []) {
                return null;
            }

            sort($v);

            return round($v[intdiv(count($v), 2)] / 3600, 1);
        };
        $rows = [
            ['figure' => 'Leads', 'value' => $leads],
            ['figure' => 'Reached (%)', 'value' => $leads === 0 ? null : round($sum['contacted'] / $leads * 100, 1)],
            ['figure' => 'Reached within 60 minutes (%)', 'value' => $leads === 0 ? null : round($sum['within'] / $leads * 100, 1)],
            ['figure' => 'Not yet answered', 'value' => $sum['uncontacted']],
            ['figure' => 'Waiting longer than 60 minutes', 'value' => $sum['overdue_uncontacted']],
            ['figure' => 'Median hours to first attempt', 'value' => $median($medians['attempt'])],
            ['figure' => 'Median hours to first contact', 'value' => $median($medians['first'])],
            ['figure' => 'Qualified', 'value' => $sum['qualified']],
        ];

        return [
            'section' => new TrackingSection(TrackingView::FollowUp, [['key' => 'figure', 'label' => 'Figure'], ['key' => 'value', 'label' => 'Value']], $leads === 0 ? [] : $rows),
            'caveats' => ['Leads from this client’s forms only. No lead is named. Medians are the middle of each project’s median, not of every lead.'],
        ];
    }

    /**
     * Every link of the client with its performance, so the five views below agree with each other and with the link page.
     *
     * @return list<array{name: string, platform: ?string, campaign: ?string, p: array<string, mixed>}>
     */
    private function performances(string $workspaceId, string $clientId, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $end = $until->modify('+1 second');
        $out = [];

        foreach (array_slice($this->links->list($workspaceId, null, null, $clientId, null), 0, 60) as $link) {
            $out[] = ['name' => $link->name, 'platform' => $link->platform, 'campaign' => $link->campaign, 'p' => ($this->performance)($link, $workspaceId, $from, $end, false)];
        }

        return $out;
    }

    private function major(?int $minor, ?string $currency): ?float
    {
        return $minor === null || $currency === null ? null : round($minor / (10 ** CurrencyExponent::for($currency)), 2);
    }

    /** @return array{section: TrackingSection, caveats: list<string>} */
    private function roi(string $workspaceId, string $clientId, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $rows = [];

        foreach ($this->performances($workspaceId, $clientId, $from, $until) as $r) {
            $p = $r['p'];
            $spend = $p['spend'];
            $c = $p['counts'];
            $cur = $spend->currency;
            $lead = $p['revenue']['lead_value'] ?? null;
            $store = $p['revenue']['store_attributed'] ?? null;

            $rows[] = [
                'link' => $r['name'], 'platform' => $r['platform'], 'currency' => $cur,
                'spend' => $this->major($spend->minor, $cur),
                'leads' => $c['lead_created'] ?? null,
                'cost_per_lead' => $this->major($p['costs']['cpl']['minor'] ?? null, $cur),
                'sales' => $c['sale_completed'] ?? null,
                'cost_per_sale' => $this->major($p['costs']['cac']['minor'] ?? null, $cur),
                'recorded_won_value' => $this->major($lead['minor'] ?? null, $lead['currency'] ?? null),
                'return_on_spend' => $lead['roas']['value'] ?? null,
                'store_orders_value' => $this->major($store['minor'] ?? null, $store['currency'] ?? null),
                'store_return_on_spend' => $store['roas']['value'] ?? null,
            ];
        }

        return [
            'section' => new TrackingSection(TrackingView::Roi, [
                ['key' => 'link', 'label' => 'Link'], ['key' => 'platform', 'label' => 'Platform'], ['key' => 'currency', 'label' => 'Currency'],
                ['key' => 'spend', 'label' => 'Spend'], ['key' => 'leads', 'label' => 'Leads'], ['key' => 'cost_per_lead', 'label' => 'Cost per lead'],
                ['key' => 'sales', 'label' => 'Sales'], ['key' => 'cost_per_sale', 'label' => 'Cost per sale'],
                ['key' => 'recorded_won_value', 'label' => 'Won value recorded on leads'], ['key' => 'return_on_spend', 'label' => 'Return on spend (won value)'],
                ['key' => 'store_orders_value', 'label' => 'Store orders carrying the link'], ['key' => 'store_return_on_spend', 'label' => 'Return on spend (store orders)'],
            ], $rows),
            'caveats' => [
                'Spend is the advertising platform’s spend for what a link is bound to, shown only where it belongs to that link alone. A figure that cannot be credited to one link is not reported.',
                'The two revenue columns are different kinds of evidence and are never added. A return needs spend and revenue in the same currency.',
            ],
        ];
    }

    /** @return array{section: TrackingSection, caveats: list<string>} */
    private function campaignFunnel(string $workspaceId, string $clientId, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $by = [];

        foreach ($this->performances($workspaceId, $clientId, $from, $until) as $r) {
            $key = $r['campaign'] ?? 'Not bound to a campaign';
            $c = $r['p']['counts'];
            $g = &$by[$key];
            $g ??= ['campaign' => $key, 'clicks' => 0, 'landing_views' => 0, 'landing_gap' => false, 'leads' => 0];
            $g['clicks'] += (int) ($c['link_click'] ?? 0);
            $g['leads'] += (int) ($c['lead_created'] ?? 0);

            if (($c['landing_view'] ?? null) === null) {
                $g['landing_gap'] = true;
            } else {
                $g['landing_views'] += (int) $c['landing_view'];
            }

            unset($g);
        }

        $rows = array_values(array_map(static fn (array $g): array => [
            'campaign' => $g['campaign'], 'clicks' => $g['clicks'],
            'landing_views' => $g['landing_gap'] ? null : $g['landing_views'],
            'leads' => $g['leads'],
            'click_to_lead' => $g['clicks'] === 0 ? null : round($g['leads'] / $g['clicks'] * 100, 1),
        ], $by));

        return [
            'section' => new TrackingSection(TrackingView::CampaignFunnel, [
                ['key' => 'campaign', 'label' => 'Campaign'], ['key' => 'clicks', 'label' => 'Clicks'], ['key' => 'landing_views', 'label' => 'Landing views'],
                ['key' => 'leads', 'label' => 'Leads'], ['key' => 'click_to_lead', 'label' => 'Click to lead (%)'],
            ], $rows),
            'caveats' => ['Landing views are reported by the destination page; where a page does not report them they are shown as not reported.'],
        ];
    }

    /** @return array{section: TrackingSection, caveats: list<string>} */
    private function leadQuality(string $workspaceId, string $clientId, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $row = DB::selectOne(
            "SELECT count(*) AS leads,
                count(*) FILTER (WHERE first_contacted_at IS NOT NULL) AS reached,
                count(*) FILTER (WHERE qualified_at IS NOT NULL) AS qualified,
                count(*) FILTER (WHERE stage = 'disqualified') AS disqualified,
                count(*) FILTER (WHERE stage = 'won') AS won
             FROM leads WHERE workspace_id = ? AND client_id = ? AND source_kind = 'form_submission' AND merged_into_id IS NULL
                AND received_at >= ? AND received_at < ?",
            [$workspaceId, $clientId, $from->format('Y-m-d H:i:sP'), $until->format('Y-m-d H:i:sP')],
        );
        $n = (int) ($row->leads ?? 0);
        $reached = (int) ($row->reached ?? 0);
        $rate = static fn (int $part, int $of): ?float => $of === 0 ? null : round($part / $of * 100, 1);
        $rows = $n === 0 ? [] : [
            ['figure' => 'Leads', 'value' => $n],
            ['figure' => 'Reached', 'value' => $reached],
            ['figure' => 'Qualified', 'value' => (int) $row->qualified],
            ['figure' => 'Not a fit', 'value' => (int) $row->disqualified],
            ['figure' => 'Qualified, of leads reached (%)', 'value' => $rate((int) $row->qualified, $reached)],
            ['figure' => 'Became a sale', 'value' => (int) $row->won],
        ];

        return [
            'section' => new TrackingSection(TrackingView::LeadQuality, [['key' => 'figure', 'label' => 'Figure'], ['key' => 'value', 'label' => 'Value']], $rows),
            'caveats' => ['Leads from this client’s forms only; no lead is named. A lead nobody has reached yet is not counted as unqualified.'],
        ];
    }

    /** @return array{section: TrackingSection, caveats: list<string>} */
    private function salesFunnel(string $workspaceId, string $clientId, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $sum = ['lead_created' => 0, 'lead_qualified' => 0, 'appointment_booked' => 0, 'sale_completed' => 0];

        foreach ($this->performances($workspaceId, $clientId, $from, $until) as $r) {
            foreach ($sum as $k => $_) {
                $sum[$k] += (int) ($r['p']['counts'][$k] ?? 0);
            }
        }

        $labels = ['lead_created' => 'Lead', 'lead_qualified' => 'Qualified', 'appointment_booked' => 'Appointment', 'sale_completed' => 'Sale'];
        $rows = [];
        $previous = null;

        foreach ($sum as $k => $count) {
            $rows[] = ['step' => $labels[$k], 'count' => $count, 'rate' => $previous === null || $previous === 0 || $count > $previous ? null : round($count / $previous * 100, 1)];
            $previous = $count;
        }

        return [
            'section' => new TrackingSection(TrackingView::SalesFunnel, [
                ['key' => 'step', 'label' => 'Step'], ['key' => 'count', 'label' => 'Count'], ['key' => 'rate', 'label' => 'From the previous step (%)'],
            ], $sum['lead_created'] === 0 ? [] : $rows),
            'caveats' => ['Counts are of leads from tracked links. An open opportunity is not a sale and is not counted as one.'],
        ];
    }

    /** @return array{section: TrackingSection, caveats: list<string>} */
    private function response(string $workspaceId, string $clientId, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $leads = 0;
        $met = 0;
        $anyPromise = false;
        $attempt = [];
        $contact = [];

        foreach ($this->projects($workspaceId, $clientId) as $projectId) {
            $sla = DB::table('lead_automation_settings')->where('project_id', $projectId)->value('sla_minutes');
            $r = $this->operations->response($workspaceId, $projectId, $from, $until, is_numeric($sla) ? (int) $sla : null);
            $leads += $r['leads'];

            if ($r['met_sla'] !== null) {
                $anyPromise = true;
                $met += $r['met_sla'];
            }

            if ($r['median_attempt_seconds'] !== null) {
                $attempt[] = $r['median_attempt_seconds'];
            }

            if ($r['median_contact_seconds'] !== null) {
                $contact[] = $r['median_contact_seconds'];
            }
        }

        $median = static function (array $v): ?float {
            if ($v === []) {
                return null;
            }

            sort($v);

            return round($v[intdiv(count($v), 2)] / 3600, 1);
        };
        $rows = $leads === 0 ? [] : [
            ['figure' => 'Leads', 'value' => $leads],
            ['figure' => 'Tried inside the promised time (%)', 'value' => $anyPromise ? round($met / $leads * 100, 1) : null],
            ['figure' => 'Median hours to the first attempt', 'value' => $median($attempt)],
            ['figure' => 'Median hours to reach the lead', 'value' => $median($contact)],
        ];

        return [
            'section' => new TrackingSection(TrackingView::Response, [['key' => 'figure', 'label' => 'Figure'], ['key' => 'value', 'label' => 'Value']], $rows),
            'caveats' => ['Aggregates only: no person is named. Where a project made no response-time promise, the share tried inside it is not reported.'],
        ];
    }

    /** @return array{section: TrackingSection, caveats: list<string>} */
    private function healthView(string $workspaceId, string $clientId, DateTimeImmutable $now): array
    {
        $facts = ['links_active' => 0, 'events_24h' => 0, 'last_event_at' => null, 'clicks_7d' => 0, 'landing_views_7d' => 0, 'clicks_with_click_id_7d' => 0,
            'leads_7d' => 0, 'leads_with_evidence_7d' => 0, 'consent' => ['unknown' => 0, 'granted' => 0, 'denied' => 0, 'not_required' => 0], 'ga4' => null, 'gtm_containers' => 0];

        foreach ($this->projects($workspaceId, $clientId) as $projectId) {
            $f = $this->health->facts($workspaceId, $projectId, null, $now);

            foreach (['links_active', 'events_24h', 'clicks_7d', 'landing_views_7d', 'clicks_with_click_id_7d', 'leads_7d', 'leads_with_evidence_7d'] as $k) {
                $facts[$k] += (int) $f[$k];
            }

            $facts['last_event_at'] = max((int) ($facts['last_event_at'] ?? 0), (int) ($f['last_event_at'] ?? 0)) ?: null;

            foreach (['unknown', 'granted', 'denied', 'not_required'] as $k) {
                $facts['consent'][$k] += (int) $f['consent'][$k];
            }
        }

        /** @var array{links_active: int, events_24h: int, last_event_at: ?int, clicks_7d: int, landing_views_7d: int, clicks_with_click_id_7d: int, leads_7d: int, leads_with_evidence_7d: int, consent: array{unknown: int, granted: int, denied: int, not_required: int}, ga4: ?array{last_synced_at: ?int, last_event_at: ?int}, gtm_containers: int} $facts */
        $rows = [];

        /* Only the first-party and quality checks: which advertising platforms are connected is the agency's business. */
        foreach (TrackingHealthComposer::compose($facts, [], $now->getTimestamp()) as $check) {
            if (in_array($check->group, ['source', 'quality'], true) && $check->key !== 'deduplication') {
                $rows[] = ['check' => $this->label($check->key), 'state' => $check->state->value, 'detail' => $check->detail];
            }
        }

        return [
            'section' => new TrackingSection(TrackingView::Health, [
                ['key' => 'check', 'label' => 'Check'], ['key' => 'state', 'label' => 'State'], ['key' => 'detail', 'label' => 'What it rests on'],
            ], $facts['links_active'] === 0 && $facts['last_event_at'] === null ? [] : $rows),
            'caveats' => ['The last seven days up to the end of the period. Provider connections and pixels are not shown.'],
        ];
    }

    /** @return list<string> */
    private function projects(string $workspaceId, string $clientId): array
    {
        return array_values(array_map(
            static fn (stdClass $r): string => (string) $r->id,
            DB::select('SELECT id FROM projects WHERE workspace_id = ? AND client_id = ? AND deleted_at IS NULL', [$workspaceId, $clientId]),
        ));
    }

    private function label(string $key): string
    {
        return match ($key) {
            FunnelSource::LinkClick->value => 'Link click',
            FunnelSource::LandingView->value => 'Landing view',
            FunnelSource::FormStart->value => 'Form started',
            FunnelSource::FormSubmit->value => 'Form submitted',
            FunnelSource::LeadCreated->value => 'Lead created',
            FunnelSource::LeadContacted->value => 'Lead reached',
            FunnelSource::LeadQualified->value => 'Lead qualified',
            FunnelSource::AppointmentBooked->value => 'Appointment',
            FunnelSource::OpportunityCreated->value => 'Opportunity',
            FunnelSource::SaleCompleted->value => 'Sale',
            'first_party' => 'First-party events',
            'click_ids' => 'Click identifiers',
            'attribution' => 'Attribution evidence',
            'consent' => 'Consent readiness',
            default => $key,
        };
    }
}
