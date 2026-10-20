<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Application\Leads\DTO\LeadBreakdownRow;
use App\Application\Leads\DTO\LeadResponseTime;
use App\Application\Leads\DTO\LeadSourceRow;
use App\Domain\Leads\LeadFunnel;

/**
 * How a client's recorded leads turned out over the report's period — aggregates and nothing else.
 *
 * Built only from the lead read model's counts. There is no field here that could hold a name, a contact point, a
 * note, a message, an owner or a stage history, so a serialiser cannot leak one by mistake.
 *
 * What it deliberately is not: the ad platforms' own `leads` metric. These are leads the client *recorded*; a
 * platform's claim sits in the ad sections and is not added to, or divided into, these.
 */
final readonly class LeadFigures
{
    /**
     * @param  list<LeadSourceRow>  $sources
     * @param  list<LeadBreakdownRow>  $campaigns
     */
    public function __construct(
        public LeadFunnel $funnel,
        public array $sources,
        public array $campaigns,
        public LeadResponseTime $response,
    ) {}

    public function isEmpty(): bool
    {
        return $this->funnel->total === 0;
    }

    /**
     * Where the period's leads stand, as counts that add up to the total.
     *
     * `new` is what is left once every other stage is taken out, so the parts cannot disagree with the whole.
     *
     * @return array<string, int>
     */
    public function stages(): array
    {
        $funnel = $this->funnel;

        return [
            'new' => max(0, $funnel->total - $funnel->contacted - $funnel->qualified - $funnel->disqualified - $funnel->won - $funnel->lost),
            'contacted' => $funnel->contacted,
            'qualified' => $funnel->qualified,
            'disqualified' => $funnel->disqualified,
            'won' => $funnel->won,
            'lost' => $funnel->lost,
        ];
    }

    /**
     * The headline facts as label and text, for the formats that have no charts (CSV, workbook, deck).
     *
     * `null` text means there is no figure — a rate with nothing to divide by, a median with no answered lead — and the
     * writer says so in words rather than printing a zero.
     *
     * @return list<array{label: string, text: string|null}>
     */
    public function facts(): array
    {
        $funnel = $this->funnel;
        $percent = static fn (?float $value): ?string => $value === null ? null : rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').'%';

        return [
            ['label' => 'Leads recorded', 'text' => (string) $funnel->total],
            ['label' => 'Reached', 'text' => (string) $funnel->reached],
            ['label' => 'Qualified', 'text' => (string) $funnel->qualified],
            ['label' => 'Qualification rate (of leads reached)', 'text' => $percent($funnel->qualificationRate())],
            ['label' => 'Won', 'text' => (string) $funnel->won],
            ['label' => 'Win rate (of closed leads)', 'text' => $percent($funnel->winRate())],
            [
                'label' => 'Median time to first contact (minutes)',
                'text' => $this->response->medianSeconds === null ? null : (string) round($this->response->medianSeconds / 60, 1),
            ],
        ];
    }
}
