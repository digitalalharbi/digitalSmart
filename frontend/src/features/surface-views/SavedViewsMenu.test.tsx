import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { SavedViewsMenu } from './SavedViewsMenu';

const state = vi.hoisted(() => ({
  views: [] as unknown[],
  saved: null as Record<string, unknown> | null,
  deleted: null as string | null,
}));

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();

  return {
    ...original,
    ensureCsrfCookie: () => Promise.resolve(),
    api: {
      get: () => Promise.resolve({ data: { data: state.views } }),
      post: (_url: string, body: Record<string, unknown>) => {
        state.saved = body;

        return Promise.resolve({ data: { data: { id: 'view-new' } } });
      },
      delete: (url: string) => {
        state.deleted = url;

        return Promise.resolve({});
      },
    },
  };
});

function view(overrides: Record<string, unknown> = {}) {
  return {
    id: 'view-1',
    surface: 'campaign_control',
    name: 'Critical this week',
    visibility: 'workspace',
    visibility_label: 'Everyone in the workspace',
    project_id: null,
    project_name: null,
    filters: { health: 'critical', q: 'ramadan' },
    author_name: 'Sara',
    is_mine: false,
    ...overrides,
  };
}

describe('SavedViewsMenu', () => {
  beforeEach(() => {
    state.views = [];
    state.saved = null;
    state.deleted = null;
  });

  it('applies a view by handing its filters back, and closes', async () => {
    state.views = [view()];
    const onApply = vi.fn();
    await renderWithProviders(
      <SavedViewsMenu surface="campaign_control" current={{}} onApply={onApply} />,
    );

    await userEvent.click(screen.getByRole('button', { name: 'Saved views' }));
    await userEvent.click(await screen.findByRole('button', { name: /Critical this week/ }));

    expect(onApply).toHaveBeenCalledWith({ health: 'critical', q: 'ramadan' });
  });

  it("offers ready-made views that set the page's own filters, with nothing saved", async () => {
    const onApply = vi.fn();
    await renderWithProviders(
      <SavedViewsMenu
        surface="campaign_control"
        current={{}}
        onApply={onApply}
        presets={[
          { key: 'critical', label: 'Critical campaigns', filters: { health: 'critical' } },
        ]}
      />,
    );

    await userEvent.click(screen.getByRole('button', { name: 'Saved views' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Critical campaigns' }));

    expect(onApply).toHaveBeenCalledWith({ health: 'critical' });
    expect(state.saved).toBeNull();
  });

  it('saves the current filters under a name, privately by default', async () => {
    await renderWithProviders(
      <SavedViewsMenu surface="leads" current={{ scope: 'mine', days: '30' }} onApply={vi.fn()} />,
    );

    await userEvent.click(screen.getByRole('button', { name: 'Saved views' }));
    await userEvent.type(await screen.findByRole('textbox'), 'My leads, a month');
    await userEvent.click(screen.getByRole('button', { name: 'Save view' }));

    await waitFor(() => {
      expect(state.saved).toEqual({
        name: 'My leads, a month',
        visibility: 'private',
        filters: { scope: 'mine', days: '30' },
      });
    });
  });

  it('cannot share with a team when no project is chosen', async () => {
    await renderWithProviders(
      <SavedViewsMenu surface="leads" current={{ days: '7' }} onApply={vi.fn()} />,
    );

    await userEvent.click(screen.getByRole('button', { name: 'Saved views' }));

    expect(await screen.findByRole('button', { name: 'The project team' })).toBeDisabled();
  });

  it('only offers deletion for a view that is the reader’s own', async () => {
    state.views = [
      view({ id: 'a', name: 'Theirs' }),
      view({ id: 'b', name: 'Mine', is_mine: true }),
    ];
    await renderWithProviders(
      <SavedViewsMenu surface="campaign_control" current={{}} onApply={vi.fn()} />,
    );

    await userEvent.click(screen.getByRole('button', { name: 'Saved views' }));
    await screen.findByRole('button', { name: /Theirs/ });

    expect(screen.queryByRole('button', { name: 'Delete Theirs' })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Delete Mine' }));
    await waitFor(() => expect(state.deleted).toBe('/views/b'));
  });
});
