import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { LinkHistoryPanel } from './LinkHistoryPanel';
import type { LinkRevision } from './types';

const state: { data: LinkRevision[] } = { data: [] };

vi.mock('./api', () => ({
  useLinkHistoryQuery: () => ({ isPending: false, isError: false, data: state.data }),
}));

function revision(over: Partial<LinkRevision>): LinkRevision {
  return {
    revision: 1,
    values: { utm_campaign: 'spring' },
    changed_fields: [],
    changed_by_name: null,
    backfilled: false,
    effective_from: '2026-10-01T09:00:00+00:00',
    effective_until: null,
    is_current: true,
    clicks: 0,
    ...over,
  };
}

describe('LinkHistoryPanel', () => {
  it('shows each version with its clicks, who changed it, and the value before and after', async () => {
    state.data = [
      revision({
        revision: 2,
        values: { utm_campaign: 'summer' },
        changed_fields: ['utm_campaign'],
        changed_by_name: 'Noura',
        effective_from: '2026-10-10T09:00:00+00:00',
        clicks: 12,
      }),
      revision({
        effective_until: '2026-10-10T09:00:00+00:00',
        is_current: false,
        clicks: 340,
      }),
    ];

    await renderWithProviders(<LinkHistoryPanel linkId="l1" />);

    expect(screen.getByText('Version 2')).toBeInTheDocument();
    expect(screen.getByText('In force now')).toBeInTheDocument();
    expect(screen.getByText(/Valid clicks while in force: 12/)).toHaveTextContent(
      'changed by Noura',
    );
    expect(screen.getByText(/Valid clicks while in force: 340/)).toBeInTheDocument();
    expect(screen.getByText('spring')).toBeInTheDocument();
    expect(screen.getByText('summer')).toBeInTheDocument();
    expect(screen.getByText(/read the link as it stands now/)).toBeInTheDocument();
  });

  it('says when the first version is the link as it stood when history began', async () => {
    state.data = [revision({ backfilled: true })];

    await renderWithProviders(<LinkHistoryPanel linkId="l1" />);

    expect(screen.getByText(/earlier changes were not recorded/)).toBeInTheDocument();
  });
});
