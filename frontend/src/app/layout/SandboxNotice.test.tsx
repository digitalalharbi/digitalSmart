import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { SandboxNotice } from './SandboxNotice';

const session = vi.hoisted(() => ({ slug: 'demo-smart-code' }));

vi.mock('@/features/auth', () => ({
  useSession: () => ({ user: { active_workspace: { slug: session.slug } } }),
}));

describe('SandboxNotice', () => {
  beforeEach(() => {
    session.slug = 'demo-smart-code';
    localStorage.clear();
  });

  it('says so in a sandbox workspace, and says nothing in a real one', async () => {
    await renderWithProviders(<SandboxNotice />);
    expect(screen.getByRole('note', { name: 'Sandbox workspace' })).toBeInTheDocument();

    session.slug = 'smart-code';
    const real = await renderWithProviders(<SandboxNotice />);
    expect(real.container).toBeEmptyDOMElement();
  });

  it('can be shortened but never removed', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<SandboxNotice />);

    expect(screen.getByText(/Every figure here is a fixture/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Hide this notice' }));

    expect(screen.queryByText(/Every figure here is a fixture/)).not.toBeInTheDocument();
    expect(screen.getByText('Sandbox agency')).toBeInTheDocument();
  });

  it('walks through six real pages and ends', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<SandboxNotice />);

    await user.click(screen.getByRole('button', { name: 'Take the tour' }));
    expect(await screen.findByText('1 / 6')).toBeInTheDocument();

    for (let step = 0; step < 5; step += 1) {
      await user.click(screen.getByRole('button', { name: 'Next' }));
    }

    expect(screen.getByText('6 / 6')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Finish' }));
    expect(screen.queryByText('6 / 6')).not.toBeInTheDocument();
  });
});
