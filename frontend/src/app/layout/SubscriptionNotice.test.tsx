import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { SubscriptionNotice } from './SubscriptionNotice';

const state = vi.hoisted(() => ({
  subscription: null as null | {
    status: string;
    reason: string | null;
    trial_ends_at: string | null;
    grace_ends_at: string | null;
  },
}));

vi.mock('@/features/auth', async () => {
  const actual = await vi.importActual<Record<string, unknown>>('@/features/auth');

  return {
    ...actual,
    useSession: () => ({ user: { subscription: state.subscription }, can: () => true }),
  };
});

describe('SubscriptionNotice', () => {
  it('says nothing for an active workspace', async () => {
    state.subscription = {
      status: 'active',
      reason: null,
      trial_ends_at: null,
      grace_ends_at: null,
    };
    const { container } = await renderWithProviders(<SubscriptionNotice />);

    expect(container).toBeEmptyDOMElement();
  });

  it('says a paused workspace is readable and not changeable, with its reason', async () => {
    state.subscription = {
      status: 'suspended',
      reason: 'Invoice unpaid',
      trial_ends_at: null,
      grace_ends_at: null,
    };
    await renderWithProviders(<SubscriptionNotice />);

    expect(screen.getByText(/everything can be read, nothing can be changed/)).toHaveTextContent(
      'Reason: Invoice unpaid',
    );
  });
});
