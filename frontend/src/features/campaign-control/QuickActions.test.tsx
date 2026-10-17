import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { QuickActions } from './QuickActions';
import type { CampaignRow } from './types';

const post = vi.hoisted(() => vi.fn());
const permissions = vi.hoisted(() => ({ current: [] as string[] }));

vi.mock('@/features/auth', () => ({
  useSession: () => ({ can: (permission: string) => permissions.current.includes(permission) }),
}));
vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();

  return { ...original, api: { post: (...args: unknown[]) => post(...args), get: vi.fn() } };
});

const row = {
  id: 'k1',
  name: 'Ramadan Push',
  client_id: 'c1',
  client_name: 'Alhambra Retail',
  project_id: 'p1',
  project_name: 'Ramadan',
  needs_attention: true,
} as CampaignRow;

describe('QuickActions', () => {
  beforeEach(() => {
    post.mockReset();
    post.mockResolvedValue({ data: {} });
    permissions.current = [];
  });

  it('offers nothing to a reader who may do nothing', async () => {
    await renderWithProviders(<QuickActions row={row} />);

    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('offers no way to change live status, whatever the reader may do', async () => {
    permissions.current = ['tasks.manage', 'reports.view'];
    await renderWithProviders(<QuickActions row={row} />);

    expect(screen.queryByRole('button', { name: /pause|resume/i })).not.toBeInTheDocument();
  });

  it('raises a task about this campaign, in its client and project', async () => {
    permissions.current = ['tasks.manage'];
    const user = userEvent.setup();
    await renderWithProviders(<QuickActions row={row} />);

    await user.click(screen.getByRole('button', { name: 'Add task' }));
    await user.click(await screen.findByRole('button', { name: 'Create task' }));

    expect(post).toHaveBeenCalledWith(
      '/tasks',
      expect.objectContaining({
        client_id: 'c1',
        project_id: 'p1',
        subject_type: 'campaign',
        subject_id: 'k1',
        priority: 'high',
      }),
    );
  });
});
