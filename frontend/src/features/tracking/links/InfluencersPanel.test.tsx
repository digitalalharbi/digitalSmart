import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { InfluencersPanel } from './InfluencersPanel';
import type { Influencer, InfluencerDraft } from './influencers';

const state = vi.hoisted(() => ({
  list: [] as Influencer[],
  saved: [] as Array<{ id?: string; draft: InfluencerDraft }>,
}));

vi.mock('./influencers', () => ({
  useInfluencersQuery: () => ({ isError: false, data: state.list }),
  useSaveInfluencerMutation: () => ({
    isPending: false,
    error: null,
    mutate: (input: { id?: string; draft: InfluencerDraft }) => {
      state.saved.push(input);
    },
  }),
}));
vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({
    data: { data: [{ id: 'c1', name: 'Alhambra' }] },
    isFetching: false,
  }),
}));

function influencer(overrides: Partial<Influencer> = {}): Influencer {
  return {
    id: 'i1',
    client: { id: 'c1', name: 'Alhambra' },
    name: 'Noura Alharbi',
    handle: '@noura',
    platform: 'snapchat',
    notes: null,
    status: 'active',
    created_at: '2026-11-06T10:00:00Z',
    totals: { links: 2, clicks: 31, visitors: 27 },
    ...overrides,
  };
}

async function open() {
  await userEvent.click(await screen.findByText('Influencers'));
}

describe('InfluencersPanel', () => {
  beforeEach(() => {
    state.list = [influencer()];
    state.saved = [];
  });

  it('lists each influencer with the links and clicks counted for them, and no money', async () => {
    await renderWithProviders(<InfluencersPanel canManage />);
    await open();

    const list = await screen.findByRole('list', { name: 'Influencers' });
    expect(list).toHaveTextContent('Noura Alharbi');
    expect(list).toHaveTextContent('Alhambra');
    expect(list).toHaveTextContent('2 links · 31 clicks');
    expect(list.textContent ?? '').not.toMatch(/\bSAR\b|\bfee\b|\bcost\b/i);
  });

  it('adds an influencer to a chosen client', async () => {
    await renderWithProviders(<InfluencersPanel canManage />);
    await open();

    await userEvent.type(await screen.findByRole('combobox', { name: /Client/ }), 'Alh');
    await userEvent.click(await screen.findByRole('option', { name: /Alhambra/ }));
    await userEvent.type(screen.getByLabelText(/Name/), 'Sara Nasser');
    await userEvent.click(screen.getByRole('button', { name: 'Add influencer' }));

    await waitFor(() => expect(state.saved).toHaveLength(1));
    expect(state.saved[0]?.draft).toEqual({ client_id: 'c1', name: 'Sara Nasser' });
  });

  it('archives rather than deleting, and offers neither to a reader who cannot manage', async () => {
    await renderWithProviders(<InfluencersPanel canManage />);
    await open();
    await userEvent.click(await screen.findByRole('button', { name: 'Archive' }));

    expect(state.saved[0]).toMatchObject({ id: 'i1', draft: { status: 'archived' } });
  });

  it('shows a reader who cannot manage the list and no controls', async () => {
    await renderWithProviders(<InfluencersPanel canManage={false} />);
    await open();

    expect(await screen.findByText('Noura Alharbi')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Archive' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Add influencer' })).not.toBeInTheDocument();
  });
});
