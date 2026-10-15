import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { useUiStore } from '@/shared/store/ui-store';
import { CommandPalette } from './CommandPalette';

const search = vi.hoisted(() => vi.fn());
const visit = vi.hoisted(() => vi.fn());
const pin = vi.hoisted(() => vi.fn());

vi.mock('@/features/auth', () => ({ useSession: () => ({ can: () => true }) }));
vi.mock('@/features/search', () => ({
  useSearchQuery: (term: string) => search(term),
  useShortcutsQuery: () => ({
    data: {
      pinned: [
        { type: 'client', id: 'c1', label: 'Alhambra Retail', context: 'Retail', path: '/clients' },
      ],
      recent: [],
    },
  }),
  usePinMutation: () => ({ mutate: pin }),
  useVisit: () => visit,
}));

describe('CommandPalette', () => {
  beforeEach(() => {
    search.mockReset();
    visit.mockReset();
    pin.mockReset();
    search.mockReturnValue({ data: undefined, isPending: false });
    useUiStore.setState({ isPaletteOpen: true, context: { client: null, project: null } });
  });

  it('offers what is pinned before anything has been typed', async () => {
    await renderWithProviders(<CommandPalette />);

    const list = screen.getByRole('listbox');
    expect(within(list).getByText('Alhambra Retail')).toBeInTheDocument();
  });

  it('asks the server only once there are two characters', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<CommandPalette />);

    await user.type(screen.getByRole('combobox'), 'a');
    expect(search).toHaveBeenLastCalledWith('a');

    search.mockReturnValue({
      data: [
        {
          type: 'project',
          id: 'p1',
          label: 'Ramadan',
          context: 'Alhambra Retail',
          path: '/projects',
        },
      ],
      isPending: false,
    });
    await user.type(screen.getByRole('combobox'), 'b');

    expect(await screen.findByText('Ramadan')).toBeInTheDocument();
  });

  /* "Take me there" and "work inside it" are different intentions, and the second is a context. */
  it('can set the working context from a client, without navigating', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<CommandPalette />);

    await user.click(screen.getByRole('button', { name: 'Work inside' }));

    expect(useUiStore.getState().context.client).toEqual({ id: 'c1', label: 'Alhambra Retail' });
    expect(useUiStore.getState().isPaletteOpen).toBe(false);
  });

  it('records a visit when a result is opened', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<CommandPalette />);

    await user.click(screen.getByText('Alhambra Retail'));

    expect(visit).toHaveBeenCalledWith(expect.objectContaining({ id: 'c1' }));
  });

  it('opens and closes from the keyboard shortcut', async () => {
    useUiStore.setState({ isPaletteOpen: false });
    await renderWithProviders(<CommandPalette />);
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();

    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }));
    });

    expect(await screen.findByRole('listbox')).toBeInTheDocument();
  });
});
