import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { CreativeIntelligence } from './CreativeIntelligence';

const figure = (metric: string, value: number) => ({
  metric,
  label: metric.toUpperCase(),
  value,
  format: 'count',
  precision: 0,
  is_minor_units: false,
  currency: null,
  source: 'synced',
  source_label: 'Synced',
  requires_label: false,
  unavailable_reason: null,
  comparison_value: null,
  change_ratio: null,
  change_is_good: null,
  lower_is_better: false,
});

const ad = (id: string, name: string, change: number, format: string) => ({
  id,
  name,
  status: 'active',
  creative: { id: `c-${id}`, name: `Art ${id}`, format, title: null, thumbnail_url: null },
  spend_minor: 1000,
  ctr_now: 2,
  ctr_change: change,
  frequency: 2.4,
});

vi.mock('@/features/auth', () => ({ useSession: () => ({ can: () => false }) }));

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();

  return {
    ...original,
    api: {
      get: (url: string) => {
        if (url.endsWith('/hierarchy')) {
          return Promise.resolve({
            data: {
              data: {
                groups: [
                  {
                    id: 'g1',
                    name: 'Group',
                    status: 'active',
                    budget_mode: 'daily',
                    budget_minor: null,
                    currency: 'SAR',
                    audience: null,
                    spend_minor: null,
                    conversions: null,
                    ads: [
                      ad('a1', 'Eid film', 0.4, 'video'),
                      ad('a2', 'Eid still', -0.4, 'image'),
                      ad('a3', 'Brand', 0, 'image'),
                    ],
                  },
                ],
                creatives: [],
                writes: { ad_group: {}, ad: {} },
              },
            },
          });
        }

        const row = (key: string, conversions: number) => ({
          dimension: 'ad',
          key,
          label: key,
          external_id: null,
          status: 'active',
          parent_key: null,
          source: 'synced',
          currency: null,
          row_count: 3,
          values: {
            impressions: figure('impressions', 8000),
            spend: figure('spend', 500),
            ctr: figure('ctr', 2),
            conversions: figure('conversions', conversions),
          },
        });

        return Promise.resolve({
          data: {
            data: {
              range: { start: '2026-09-17', end: '2026-10-16', days: 30 },
              compare_to: null,
              metrics: [],
              source: 'synced',
              source_label: 'Synced',
              requires_label: false,
              is_empty: false,
              summary: { source: 'synced', currency: null, row_count: 3, values: {} },
              series: [],
              breakdown: [row('a1', 9), row('a2', 2), row('a3', 4)],
              dimension: 'ad',
            },
          },
        });
      },
    },
  };
});

describe('CreativeIntelligence', () => {
  it('opens on the top creatives and counts each view', async () => {
    await renderWithProviders(<CreativeIntelligence campaignId="c1" />);

    const views = within(await screen.findByRole('group', { name: 'Creative view' }));

    expect(views.getByRole('button', { name: /Top · 3/ })).toBeInTheDocument();
    expect(views.getByRole('button', { name: /Rising · 1/ })).toBeInTheDocument();
    expect(views.getByRole('button', { name: /Fatiguing · 1/ })).toBeInTheDocument();
  });

  it('shows a rising creative under Rising and a fatiguing one under Fatiguing, each with its state written', async () => {
    await renderWithProviders(<CreativeIntelligence campaignId="c1" />);
    const views = within(await screen.findByRole('group', { name: 'Creative view' }));

    await userEvent.click(views.getByRole('button', { name: /Rising/ }));
    expect(await screen.findByText('Eid film')).toBeInTheDocument();
    expect(screen.queryByText('Eid still')).not.toBeInTheDocument();

    await userEvent.click(views.getByRole('button', { name: /Fatiguing/ }));
    expect(await screen.findByText('Eid still')).toBeInTheDocument();
  });

  it('compares two to four creatives, and not with one', async () => {
    await renderWithProviders(<CreativeIntelligence campaignId="c1" />);
    await screen.findByText('Eid film');

    const boxes = screen.getAllByRole('checkbox');
    await userEvent.click(boxes[0] as HTMLElement);

    expect(screen.getByText('Choose one more to compare.')).toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Comparison' })).not.toBeInTheDocument();

    await userEvent.click(boxes[1] as HTMLElement);

    expect(await screen.findByRole('group', { name: 'Comparison' })).toBeInTheDocument();
  });
});
