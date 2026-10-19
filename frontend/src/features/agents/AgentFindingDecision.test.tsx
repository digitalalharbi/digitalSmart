import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { AgentFindingDecision } from './AgentFindingDecision';
import type { AgentFinding } from './api';

const base: AgentFinding = {
  id: 'f1',
  agent: 'creative',
  reason: { code: 'CREATIVE_FATIGUE', params: { drop: 50 } },
  message: 'English fallback',
  severity: 'needs_attention',
  subject: { kind: 'Ad', id: 'ad-1', label: 'Hero video — Ramadan' },
  client: { id: 'c1', name: 'Alhambra' },
  project: { id: 'p1', name: 'Meta' },
  evidence: {
    ctr_now: 0.005,
    ctr_before: 0.01,
    thumbnail_url: '/demo-creatives/creative-01.svg',
    campaign_id: 'cmp-1',
    winner: { name: 'Static B', ctr_now: 0.02, thumbnail_url: 'https://evil.example/x.png' },
  },
  task_id: 't1',
  since: '2026-10-19T00:00:00Z',
};

describe('AgentFindingDecision', () => {
  it('shows the tiring ad, the click-through before and after, and who prepared it', async () => {
    await renderWithProviders(<AgentFindingDecision finding={base} />);

    expect(screen.getByRole('heading', { name: /CTR down 50%/ })).toBeInTheDocument();
    expect(screen.getByText(/Prepared by the Creative agent/)).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Hero video — Ramadan' })).toHaveAttribute(
      'src',
      '/demo-creatives/creative-01.svg',
    );
    // A remote preview is never rendered inline.
    expect(screen.queryByRole('img', { name: 'Static B' })).toBeNull();
    expect(screen.getByRole('link', { name: 'Campaign' })).toHaveAttribute(
      'href',
      '/campaigns/cmp-1',
    );
  });

  it('keeps leads, qualified leads and sales as three separate counts', async () => {
    await renderWithProviders(
      <AgentFindingDecision
        finding={{
          ...base,
          agent: 'leads',
          reason: { code: 'LEADS_DECLINE', params: { drop: 60 } },
          subject: { kind: 'Client', id: 'c1', label: 'Alhambra' },
          evidence: { leads: 12, qualified: 1, won: 0, leads_before: 30 },
        }}
      />,
    );

    expect(screen.getAllByRole('term').map((node) => node.textContent)).toEqual([
      'Leads',
      'Qualified',
      'Sales',
    ]);
    expect(screen.getByText('30')).toBeInTheDocument();
  });
});
