import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { HealthTab } from './HealthTab';
import type { HealthReport } from './types';

const state = vi.hoisted((): { report: HealthReport | undefined } => ({ report: undefined }));

vi.mock('./api', () => ({
  useHealthQuery: (projectId: string | null) => ({
    isPending: false,
    isError: false,
    data: projectId === null ? undefined : state.report,
  }),
}));
vi.mock('../links/api', () => ({ useLinksQuery: () => ({ data: [] }) }));
vi.mock('./ProjectScope', () => ({
  ProjectScope: ({ onChange }: { onChange: (v: { id: string; label: string } | null) => void }) => (
    <button type="button" onClick={() => onChange({ id: 'p1', label: 'Always-on' })}>
      choose project
    </button>
  ),
}));

const REPORT: HealthReport = {
  scope: 'project',
  summary: { needs_attention: 2, by_state: { PARTIAL: 1, NOT_CONFIGURED: 1 } },
  checks: [
    {
      key: 'first_party',
      group: 'source',
      state: 'PARTIAL',
      needs_attention: true,
      detail: 'Clicks are recorded but no landing page has reported a view.',
      evidence: { clicks_7d: 10, landing_views_7d: 0 },
    },
    {
      key: 'meta_browser',
      group: 'browser',
      state: 'NOT_CONFIGURED',
      needs_attention: true,
      detail: 'Meta’s pixel firing is not observed by digitalSmart.',
      evidence: {},
    },
    {
      key: 'meta_server',
      group: 'server',
      state: 'AWAITING_CREDENTIALS',
      needs_attention: false,
      detail: 'Meta is not connected.',
      evidence: {},
    },
    {
      key: 'x_server',
      group: 'server',
      state: 'UNSUPPORTED',
      needs_attention: false,
      detail: 'No server path is documented for X.',
      evidence: {},
    },
  ],
};

describe('HealthTab', () => {
  beforeEach(() => {
    state.report = REPORT;
  });

  it('asks for a project before saying anything', async () => {
    await renderWithProviders(<HealthTab />);

    expect(screen.getByText('Choose a project')).toBeInTheDocument();
  });

  it('shows every verdict as a word with its reason and evidence, and counts only what needs attention', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<HealthTab />);
    await user.click(screen.getByRole('button', { name: 'choose project' }));

    expect(screen.getByRole('status')).toHaveTextContent('2 items need attention.');
    const first = screen.getByText('First-party events').closest('li') as HTMLElement;
    expect(within(first).getByText('Partial')).toBeInTheDocument();
    expect(within(first).getByText(/clicks_7d: 10 · landing_views_7d: 0/)).toBeInTheDocument();

    const pixel = screen.getByText('Meta — browser').closest('li') as HTMLElement;
    expect(within(pixel).getByText('Not configured')).toBeInTheDocument();
    expect(screen.getByText('Awaiting credentials')).toBeInTheDocument();
    expect(screen.getByText('Unsupported')).toBeInTheDocument();
    expect(screen.queryByText('Live')).not.toBeInTheDocument();
  });
});
