import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { ActivationApproval } from './ActivationApproval';
import type { Plan } from './api';

const plan = {
  id: 'p1',
  name: 'Ramadan Sale',
  budget_type: 'daily',
  budget_minor: 50000,
  currency: 'SAR',
  starts_at: '2026-12-01T00:00:00+00:00',
  ends_at: null,
  targets: [
    { external_account_id: 'a', provider: 'meta', name: 'SANDBOX — Meta', currency: 'SAR' },
  ],
  conversion: { id: 'c', name: 'Lead form sent' },
  tracking_link: { id: 't', name: 'Ramadan landing', destination_url: 'https://example.test' },
  destination: 'https://example.test',
  targeting: {
    countries: ['SA'],
    age_min: 25,
    age_max: 44,
    gender: 'all',
    languages: ['ar'],
    interests: null,
  },
} as unknown as Plan;

describe('ActivationApproval', () => {
  it('shows everything that will start spending, and switches nothing on until the approver says they read it', async () => {
    const user = userEvent.setup();
    const approve = vi.fn();
    await renderWithProviders(
      <ActivationApproval plan={plan} pending={false} onApprove={approve} />,
    );

    expect(screen.getByText('Meta Ads · SANDBOX — Meta')).toBeInTheDocument();
    expect(screen.getByText('Lead form sent')).toBeInTheDocument();
    expect(screen.getByText('Ramadan landing')).toBeInTheDocument();
    expect(screen.getByText('SA · 25–44 · ar')).toBeInTheDocument();

    const button = screen.getByRole('button', { name: 'Switch on' });
    expect(button).toBeDisabled();
    await user.click(button);
    expect(approve).not.toHaveBeenCalled();

    await user.click(screen.getByRole('checkbox'));
    await user.click(button);
    expect(approve).toHaveBeenCalledOnce();
  });
});
