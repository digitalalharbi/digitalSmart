import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { RolesPanel } from './RolesPanel';

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();

  return {
    ...original,
    api: {
      get: () =>
        Promise.resolve({
          data: {
            data: {
              roles: [
                {
                  slug: 'media-buyer',
                  name: 'Media buyer',
                  description: 'Executes the projects they are assigned to.',
                  is_system: true,
                  members: 2,
                  permissions: ['analytics.view'],
                },
              ],
              permissions: [
                { slug: 'analytics.view', group: 'analytics', description: 'View analytics' },
              ],
            },
          },
        }),
    },
  };
});

describe('RolesPanel', () => {
  it('shows what a role is, how many hold it, and each permission in plain words', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<RolesPanel />);

    expect(await screen.findByRole('heading', { name: 'Media buyer' })).toBeInTheDocument();
    expect(screen.getByText('2 people')).toBeInTheDocument();

    await user.click(screen.getByText('1 permission'));

    expect(screen.getByText('View analytics')).toBeInTheDocument();
    expect(screen.queryByText('analytics.view')).not.toBeInTheDocument();
  });
});
