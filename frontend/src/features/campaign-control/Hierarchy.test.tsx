import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { Hierarchy } from './Hierarchy';

const get = vi.hoisted(() => vi.fn());
const post = vi.hoisted(() => vi.fn());
const permissions = vi.hoisted(() => ({ current: [] as string[] }));

vi.mock('@/features/auth', () => ({
  useSession: () => ({ can: (permission: string) => permissions.current.includes(permission) }),
}));
vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();

  return {
    ...original,
    api: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args) },
  };
});

const creative = { id: 'cr1', name: 'Eid hero', format: 'image', title: null, thumbnail_url: null };
const ad = (id: string, name: string, status = 'active') => ({
  id,
  name,
  status,
  creative,
  spend_minor: 1000,
  ctr_now: 0.012,
  ctr_change: null,
  frequency: 1.4,
});

/** A campaign whose platform pauses ad groups and does not pause ads — the X shape. */
const hierarchy = {
  groups: [
    {
      id: 'g1',
      name: 'Riyadh 25-34',
      status: 'active',
      budget_mode: 'daily',
      budget_minor: 50000,
      currency: 'SAR',
      audience: null,
      spend_minor: 12000,
      conversions: 3,
      ads: [ad('a1', 'Hero A'), ad('a2', 'Hero B')],
    },
  ],
  creatives: [{ ...creative, ads: 2, ad_names: ['Hero A', 'Hero B'] }],
  writes: {
    ad_group: {
      pause: { support: 'documented', because: null },
      resume: { support: 'documented', because: null },
    },
    ad: {
      pause: { support: 'not_offered', because: 'X: promoted Tweets cannot be updated.' },
      resume: { support: 'not_offered', because: 'X: promoted Tweets cannot be updated.' },
    },
  },
};

describe('Hierarchy', () => {
  beforeEach(() => {
    get.mockReset();
    post.mockReset();
    get.mockResolvedValue({ data: { data: hierarchy } });
    post.mockResolvedValue({ data: { data: { done: 2, refused: 0 } } });
    permissions.current = ['approvals.request', 'tasks.manage'];
  });

  it('asks about an ad group where its platform documents the change, and says why not for an ad', async () => {
    await renderWithProviders(<Hierarchy campaignId="c1" defaultOpen />);

    expect(await screen.findByRole('button', { name: 'Ask to pause: Riyadh 25-34' })).toBeEnabled();

    const ads = screen.getByRole('list', { name: 'Ads in Riyadh 25-34' });
    const refused = within(ads).getAllByRole('button', {
      name: /Ask to pause — X: promoted Tweets/,
    });
    expect(refused).toHaveLength(2);
    refused.forEach((button) => expect(button).toBeDisabled());
  });

  it('lists each creative once, with the ads that run it', async () => {
    await renderWithProviders(<Hierarchy campaignId="c1" defaultOpen />);

    const creatives = await screen.findByRole('region', { name: 'Creatives' });
    expect(within(creatives).getByText('Eid hero')).toBeInTheDocument();
    expect(within(creatives).getByText('Used by 2 ads')).toBeInTheDocument();
  });

  it('acts on several ads at once, through the bulk endpoint, at their own level', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<Hierarchy campaignId="c1" defaultOpen />);

    await user.click(await screen.findByRole('checkbox', { name: 'Choose Hero A' }));
    await user.click(screen.getByRole('checkbox', { name: 'Choose Hero B' }));

    const bar = screen.getByRole('region', { name: 'Act on the chosen' });
    expect(within(bar).getByText('2 ads chosen')).toBeInTheDocument();

    await user.selectOptions(
      within(bar).getByRole('combobox', { name: 'What to do' }),
      'create_task',
    );
    await user.type(
      within(bar).getByRole('textbox', { name: 'Why (one sentence)' }),
      'Refresh the creative',
    );
    await user.click(within(bar).getByRole('button', { name: 'Apply' }));

    expect(post).toHaveBeenCalledWith('/campaigns/entities/bulk', {
      level: 'ad',
      ids: ['a1', 'a2'],
      action: 'create_task',
      reason: 'Refresh the creative',
    });
  });

  it('offers nothing to choose or ask to a reader who may do neither', async () => {
    permissions.current = [];
    await renderWithProviders(<Hierarchy campaignId="c1" defaultOpen />);

    await screen.findByText('Riyadh 25-34');
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Ask to/ })).not.toBeInTheDocument();
  });
});
