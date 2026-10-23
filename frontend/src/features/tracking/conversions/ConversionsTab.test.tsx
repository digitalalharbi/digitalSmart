import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { ConversionsTab } from './ConversionsTab';
import type { Conversions } from './types';

const state = vi.hoisted(
  (): { data: Conversions | undefined; retry: ReturnType<typeof vi.fn> } => ({
    data: undefined,
    retry: vi.fn(),
  }),
);

vi.mock('./api', () => ({
  useConversionsQuery: (id: string | null) => ({
    isPending: false,
    isError: false,
    data: id === null ? undefined : state.data,
  }),
  useSaveMappingMutation: () => ({ isPending: false, isError: false, mutate: vi.fn() }),
  useDeleteMappingMutation: () => ({ isPending: false, mutate: vi.fn() }),
  useSavePolicyMutation: () => ({ isPending: false, isError: false, mutate: vi.fn() }),
  useRetryDispatchMutation: () => ({ isPending: false, mutate: state.retry }),
}));
vi.mock('@/features/auth', () => ({
  PERMISSIONS: { trackingManage: 'tracking.manage' },
  useSession: () => ({ can: () => true }),
}));
vi.mock('../health/ProjectScope', () => ({
  ProjectScope: ({ onChange }: { onChange: (v: { id: string; label: string }) => void }) => (
    <button type="button" onClick={() => onChange({ id: 'p1', label: 'Always-on' })}>
      choose project
    </button>
  ),
}));

const DATA: Conversions = {
  events: ['form_submit', 'lead_created'],
  providers: [
    {
      provider: 'snapchat',
      label: 'Snapchat',
      support: 'DOCUMENTED',
      mechanism: 'Conversions API',
      events: ['SIGN_UP', 'PURCHASE'],
      event_rule: 'One of the documented names.',
      dedup: 'event_id',
      needs_click_id: [],
      source: 'https://developers.snap.com/x',
      caveat: null,
      read_on: '2026-10-23',
    },
    {
      provider: 'tiktok',
      label: 'TikTok',
      support: 'UNVERIFIED',
      mechanism: null,
      events: null,
      event_rule: null,
      dedup: null,
      needs_click_id: [],
      source: null,
      caveat: 'TikTok has an events API, but its documentation could not be read.',
      read_on: null,
    },
    {
      provider: 'x',
      label: 'X',
      support: 'UNSUPPORTED',
      mechanism: null,
      events: null,
      event_rule: null,
      dedup: null,
      needs_click_id: [],
      source: null,
      caveat: 'No server path.',
      read_on: null,
    },
  ],
  mappings: [
    {
      id: 'm1',
      event: 'form_submit',
      provider: 'snapchat',
      provider_event: 'SIGN_UP',
      enabled: true,
    },
  ],
  policy: { consent_policy: 'required', retention_days: 400 },
  dispatches: [
    {
      id: 'd1',
      event: 'form_submit',
      provider: 'snapchat',
      provider_event: 'SIGN_UP',
      event_id: 'e1',
      channel: 'server',
      status: 'sent',
      skip_reason: null,
      source_state: 'SANDBOX',
      attempts: 1,
      error_category: null,
      occurred_at: '2026-10-23T08:00:00Z',
      server_sent_at: '2026-10-23T08:00:01Z',
      provider_received: null,
      deduplicated: null,
    },
    {
      id: 'd2',
      event: 'lead_created',
      provider: 'snapchat',
      provider_event: 'SIGN_UP',
      event_id: 'e2',
      channel: 'server',
      status: 'skipped',
      skip_reason: 'consent',
      source_state: null,
      attempts: 0,
      error_category: null,
      occurred_at: '2026-10-23T08:01:00Z',
      server_sent_at: null,
      provider_received: null,
      deduplicated: null,
    },
    {
      id: 'd3',
      event: 'form_submit',
      provider: 'snapchat',
      provider_event: 'SIGN_UP',
      event_id: 'e3',
      channel: 'server',
      status: 'unknown',
      skip_reason: null,
      source_state: null,
      attempts: 1,
      error_category: null,
      occurred_at: '2026-10-23T08:02:00Z',
      server_sent_at: null,
      provider_received: null,
      deduplicated: null,
    },
  ],
};

describe('ConversionsTab', () => {
  beforeEach(() => {
    state.data = DATA;
    state.retry.mockClear();
  });

  it('shows each provider as its documentation allows, with the caveat and no mapping offered for an unverified one', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<ConversionsTab />);
    await user.click(screen.getByRole('button', { name: 'choose project' }));

    expect(
      screen.getByText('TikTok has an events API, but its documentation could not be read.'),
    ).toBeInTheDocument();
    const provider = screen.getByRole('combobox', { name: 'Provider' });
    expect(within(provider).getByRole('option', { name: /TikTok — Not verified/ })).toBeDisabled();
    expect(within(provider).getByRole('option', { name: /^X — Unsupported/ })).toBeDisabled();
    expect(within(provider).getByRole('option', { name: 'Snapchat' })).toBeEnabled();
  });

  it('lists every dispatch with the reason it was not sent and never claims a provider confirmed what it did not', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<ConversionsTab />);
    await user.click(screen.getByRole('button', { name: 'choose project' }));

    const log = screen.getByRole('region', { name: 'Dispatch log' });
    expect(within(log).getByText('The visitor did not consent')).toBeInTheDocument();
    expect(within(log).getByText('Simulated (sandbox)')).toBeInTheDocument();
    expect(within(log).getAllByText('No answer from the provider').length).toBe(3);
    expect(within(log).queryByText('Provider confirmed receipt')).not.toBeInTheDocument();
  });

  it('offers a retry only for a failed or unknown outcome', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<ConversionsTab />);
    await user.click(screen.getByRole('button', { name: 'choose project' }));

    const retries = screen.getAllByRole('button', { name: 'Send again' });
    expect(retries).toHaveLength(1);
    await user.click(retries[0] as HTMLElement);
    expect(state.retry).toHaveBeenCalledWith('d3');
  });
});
