import { describe, expect, it } from 'vitest';
import { leadAdvice, leadCampaigns, leadFunnel, leadInsights, routeShare } from './leadHighlights';
import type { LeadAggregates } from './types';

function lead(overrides: Partial<LeadAggregates> = {}): LeadAggregates {
  return {
    total: 40,
    reached: 24,
    qualified: 10,
    disqualified: 4,
    won: 6,
    lost: 4,
    open: 26,
    qualification_rate: 41.7,
    win_rate: 42.9,
    stages: { new: 10, contacted: 6, qualified: 10, disqualified: 4, won: 6, lost: 4 },
    sources: [
      {
        kind: 'form_submission',
        label: 'Form submission',
        total: 20,
        qualified: 4,
        is_measured: true,
      },
      { kind: 'phone_call', label: 'Phone call', total: 15, qualified: 9, is_measured: true },
      { kind: 'manual', label: 'Entered by hand', total: 5, qualified: 1, is_measured: false },
    ],
    campaigns: [
      { label: 'Spring', is_unattributed: false, total: 12, qualified: 5, won: 2, closed: 4 },
      { label: 'Brand', is_unattributed: false, total: 8, qualified: 0, won: 0, closed: 1 },
      { label: 'Tiny', is_unattributed: false, total: 2, qualified: 0, won: 0, closed: 0 },
      { label: null, is_unattributed: true, total: 18, qualified: 5, won: 4, closed: 8 },
    ],
    response: { measured: 30, unanswered: 10, median_seconds: 5400, buckets: [5, 5, 8, 8, 4] },
    ...overrides,
  };
}

describe('leadFunnel', () => {
  it('has the three stages the lead table carries, and qualified is not one of them', () => {
    expect(leadFunnel(lead())?.map((stage) => stage.key)).toEqual(['leads', 'reached', 'won']);
  });

  it('has no funnel with no leads, rather than a row of zeroes', () => {
    expect(leadFunnel(lead({ total: 0, reached: 0, won: 0 }))).toBeNull();
  });
});

describe('routeShare', () => {
  it('says nothing about a route with too few leads', () => {
    expect(routeShare({ total: 4, qualified: 4 })).toBeNull();
    expect(routeShare({ total: 5, qualified: 1 })).toBe(20);
  });
});

describe('leadInsights', () => {
  it('names the route that qualifies most, from routes with enough leads', () => {
    const best = leadInsights(lead()).find((insight) => insight.key === 'best-route');

    // Phone: 9 of 15 = 60%. The 5-lead route is judged too, but it is not the best.
    expect(best?.values).toEqual({ route: 'Phone call', share: 60 });
  });

  it('does not crown a route that has no rival', () => {
    const only = lead({ sources: [lead().sources[0]!] });

    expect(leadInsights(only).some((insight) => insight.key === 'best-route')).toBe(false);
  });

  it('points at a slow median and at leads nobody has answered', () => {
    const keys = leadInsights(lead()).map((insight) => insight.key);

    expect(keys).toContain('slow-response');
    expect(keys).toContain('unanswered');
  });

  it('says nothing about speed when no lead has a recorded contact', () => {
    const none = lead({
      response: { measured: 0, unanswered: 3, median_seconds: null, buckets: [0, 0, 0, 0, 0] },
    });

    expect(leadInsights(none).some((insight) => insight.key.includes('response'))).toBe(false);
  });

  it('is silent when there is nothing to say', () => {
    const quiet = lead({
      sources: [],
      response: { measured: 4, unanswered: 0, median_seconds: 2400, buckets: [0, 0, 4, 0, 0] },
    });

    expect(leadInsights(quiet)).toEqual([]);
  });
});

describe('leadAdvice', () => {
  it('cites both figures it rests on', () => {
    const advice = leadAdvice(lead()).find((item) => item.key === 'prioritise-route');

    // 60% against 25% overall (10 of 40).
    expect(advice?.values).toMatchObject({ route: 'Phone call', share: 60, overall: 25 });
  });

  it('gives no pointer on thin evidence', () => {
    expect(
      leadAdvice(
        lead({
          total: 3,
          sources: [],
          response: { measured: 2, unanswered: 1, median_seconds: 9000, buckets: [0, 0, 0, 2, 0] },
        }),
      ),
    ).toEqual([]);
  });
});

describe('leadCampaigns', () => {
  it('ranks named campaigns only, and flags a campaign with enough leads and none qualified', () => {
    const { top, attention } = leadCampaigns(lead());

    expect(top.map((card) => card.label)).toEqual(['Spring', 'Brand', 'Tiny']);
    expect(attention.map((card) => card.label)).toEqual(['Brand']);
  });

  it('never presents the unattributed row as a campaign', () => {
    expect(leadCampaigns(lead()).top.some((card) => card.label === '')).toBe(false);
  });
});
