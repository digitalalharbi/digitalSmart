import { describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { LaunchExecutions } from './LaunchExecutions';

const base = {
  launch_id: 'l1',
  external_account_id: 'a',
  attempt: 1,
  provider_ids: { campaign: null, ad_group: null, ads: [] },
  readback: null,
  failure_classification: null,
};

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();

  return {
    ...original,
    api: {
      get: () =>
        Promise.resolve({
          data: {
            data: [
              {
                ...base,
                id: 'e1',
                provider: 'meta',
                status: 'verified',
                source: 'sandbox',
                message: null,
                may_retry: false,
                may_reconcile: false,
                provider_ids: {
                  campaign: 'SANDBOX-CAMPAIGN-1',
                  ad_group: 'SANDBOX-ADGROUP-1',
                  ads: [],
                },
              },
              {
                ...base,
                id: 'e2',
                provider: 'google_ads',
                status: 'unknown',
                source: 'sandbox',
                message: 'The platform did not answer.',
                may_retry: false,
                may_reconcile: true,
              },
              {
                ...base,
                id: 'e3',
                provider: 'tiktok',
                status: 'failed',
                source: 'sandbox',
                message: 'Refused.',
                may_retry: true,
                may_reconcile: false,
              },
              {
                ...base,
                id: 'e4',
                provider: 'snapchat',
                status: 'verified',
                source: 'sandbox',
                message: null,
                may_retry: false,
                may_reconcile: true,
                activation_status: 'unknown',
                activation_message: 'The platform did not answer.',
                provider_ids: { campaign: 'SANDBOX-CAMPAIGN-4', ad_group: null, ads: [] },
              },
            ],
          },
        }),
    },
  };
});

describe('LaunchExecutions', () => {
  it('offers reconcile for an unknown outcome and retry only for a proven failure', async () => {
    await renderWithProviders(<LaunchExecutions planId="p1" canExecute />);

    const items = await screen.findAllByRole('listitem');
    const [meta, google, tiktok] = items as [HTMLElement, HTMLElement, HTMLElement];

    expect(within(meta).getByText('Created paused and read back')).toBeInTheDocument();
    expect(within(meta).getByText('SANDBOX')).toBeInTheDocument();
    expect(within(meta).queryByRole('button')).not.toBeInTheDocument();
    expect(within(google).getByRole('button', { name: 'Check the platform' })).toBeInTheDocument();
    expect(within(google).queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument();
    expect(within(tiktok).getByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });

  it('offers nothing to somebody who may not create', async () => {
    await renderWithProviders(<LaunchExecutions planId="p1" canExecute={false} />);

    await screen.findAllByRole('listitem');
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('shows an unanswered switch-on as unknown and offers to check the platform, not to switch on again', async () => {
    await renderWithProviders(<LaunchExecutions planId="p1" canExecute />);

    const items = await screen.findAllByRole('listitem');
    const snapchat = items[3] as HTMLElement;

    expect(within(snapchat).getByText(/Outcome unknown, check the platform/)).toBeInTheDocument();
    expect(
      within(snapchat).getByRole('button', { name: 'Check the platform' }),
    ).toBeInTheDocument();
    expect(within(snapchat).queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument();
  });
});
