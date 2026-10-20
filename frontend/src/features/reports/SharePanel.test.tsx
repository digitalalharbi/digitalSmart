import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { SharePanel } from './SharePanel';

const base = {
  shares: [],
  snapshots: [],
  maxDays: 90,
  canShare: true,
  isInternal: false,
  isWorking: false,
  error: null,
  onShare: vi.fn(),
  onRevoke: vi.fn(),
  onTakeSnapshot: vi.fn(),
};

describe('SharePanel', () => {
  it('offers WhatsApp with words only: the client, the title, the period and the link', async () => {
    await renderWithProviders(
      <SharePanel
        {...base}
        freshToken="abc123"
        context={{
          client: 'Alhambra Retail',
          title: 'Monthly performance',
          period: '2026-09-01 – 2026-09-30',
        }}
      />,
    );

    const link = screen.getByRole('link', { name: 'Share on WhatsApp' });
    const text = decodeURIComponent(
      new URL(link.getAttribute('href') ?? '').searchParams.get('text') ?? '',
    );

    expect(link.getAttribute('href')).toMatch(/^https:\/\/wa\.me\/\?text=/);
    expect(text).toContain('Alhambra Retail');
    expect(text).toContain('Monthly performance');
    expect(text).toContain('/shared-reports/abc123');
    /* No figure: the only digits are the period's dates, the origin's port and the token. */
    expect(
      text
        .replace(window.location.origin, '')
        .replace(/2026-09-\d\d/g, '')
        .replace('abc123', ''),
    ).not.toMatch(/\d/);
  });

  it('does not offer it before there is a link to send', async () => {
    await renderWithProviders(
      <SharePanel {...base} freshToken={null} context={{ client: 'A', title: 'B', period: 'C' }} />,
    );

    expect(screen.queryByRole('link', { name: 'Share on WhatsApp' })).not.toBeInTheDocument();
  });

  it('shows a link as a card: who, how many views, when it ends — and the way to turn it off', async () => {
    await renderWithProviders(
      <SharePanel
        {...base}
        freshToken={null}
        shares={[
          {
            id: 's1',
            hint: 'AAAAAAAAAAAA',
            state: 'active',
            state_label: 'Active',
            is_frozen: false,
            expires_at: '2026-12-01T00:00:00Z',
            revoked_at: null,
            revoked_reason: null,
            recipient_label: 'Noura',
            view_count: 4,
            last_viewed_at: null,
            created_by_name: null,
            created_at: '2026-10-01T00:00:00Z',
          },
        ]}
      />,
    );

    expect(screen.getByText('Noura')).toBeInTheDocument();
    expect(screen.getByText('4')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Turn off' })).toBeInTheDocument();
  });
});
