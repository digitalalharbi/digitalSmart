import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { OwnershipQueue } from './OwnershipQueue';
import type { OwnershipQueue as Queue } from './useOwnershipQueue';

const state = vi.hoisted(() => ({
  queue: undefined as Queue | undefined,
  assigned: [] as unknown[],
}));

vi.mock('./useOwnershipQueue', () => ({
  useOwnershipQueue: () => ({ data: state.queue }),
  useBulkAssign: () => ({
    mutate: (input: unknown, options?: { onSuccess?: () => void }) => {
      state.assigned.push(input);
      options?.onSuccess?.();
    },
    isPending: false,
    data: undefined,
  }),
}));

function queue(): Queue {
  return {
    data: [
      {
        id: 'c1',
        name: 'Ramadan Push',
        provider: 'meta',
        external_account: { id: 'a1', name: 'Meta KSA' },
        ownership: 'ambiguous',
        ownership_label: 'Several projects',
        candidate_projects: [
          { id: 'p1', name: 'Brand' },
          { id: 'p2', name: 'Leads' },
        ],
      },
      {
        id: 'c2',
        name: 'Eid Push',
        provider: 'meta',
        external_account: { id: 'a1', name: 'Meta KSA' },
        ownership: 'ambiguous',
        ownership_label: 'Several projects',
        candidate_projects: [{ id: 'p1', name: 'Brand' }],
      },
      {
        id: 'c3',
        name: 'Orphan',
        provider: 'google',
        external_account: { id: 'a9', name: 'Google KSA' },
        ownership: 'unbound',
        ownership_label: 'No project',
        candidate_projects: [],
      },
    ],
    meta: { counts: { ambiguous: 2, unbound: 1 } },
  };
}

describe('OwnershipQueue', () => {
  beforeEach(() => {
    state.queue = queue();
    state.assigned = [];
  });

  it('renders nothing when every campaign has a place', async () => {
    state.queue = { data: [], meta: { counts: { assigned: 12 } } };
    const { container } = await renderWithProviders(<OwnershipQueue />);

    expect(container).toBeEmptyDOMElement();
  });

  it('offers only the projects every selected campaign may join', async () => {
    await renderWithProviders(<OwnershipQueue />);

    await userEvent.click(screen.getByLabelText(/Ramadan Push/));
    expect(screen.getByRole('option', { name: 'Leads' })).toBeInTheDocument();

    await userEvent.click(screen.getByLabelText(/Eid Push/));
    expect(screen.queryByRole('option', { name: 'Leads' })).not.toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Brand' })).toBeInTheDocument();
  });

  it('cannot select a campaign whose account is bound to nothing', async () => {
    await renderWithProviders(<OwnershipQueue />);

    expect(screen.getByLabelText(/Orphan/)).toBeDisabled();
    expect(screen.getByText(/Bind its account to a project first/)).toBeInTheDocument();
  });

  it('places the selection in the chosen project', async () => {
    await renderWithProviders(<OwnershipQueue />);

    await userEvent.click(screen.getByLabelText(/Ramadan Push/));
    await userEvent.click(screen.getByLabelText(/Eid Push/));
    await userEvent.selectOptions(screen.getByRole('combobox'), 'p1');
    await userEvent.click(screen.getByRole('button', { name: /Place 2 campaigns/ }));

    await waitFor(() => {
      expect(state.assigned).toEqual([{ campaignIds: ['c1', 'c2'], projectId: 'p1' }]);
    });
  });
});
