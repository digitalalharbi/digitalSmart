import { describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { ObservedEventsChart } from './ObservedEventsChart';
import type { EventDefinition } from './types';

vi.mock('@/shared/ui/chart/Chart', () => ({
  Chart: ({ ariaLabel }: { ariaLabel: string }) => <div data-testid="chart">{ariaLabel}</div>,
}));

function definition(overrides: Partial<EventDefinition> = {}): EventDefinition {
  return {
    id: 'definition-1',
    client_id: 'client-1',
    project_id: null,
    name: 'Qualified lead',
    normalized_event: 'lead',
    normalized_event_label: 'Lead',
    is_conversion: true,
    is_primary: true,
    expects_value: false,
    is_intent_signal_only: false,
    description: null,
    mappings: [],
    observed_count: 42,
    last_seen_date: '2026-10-05',
    ...overrides,
  };
}

async function openTable(): Promise<HTMLElement> {
  const frame = await screen.findByRole('region', { name: 'What has actually been seen' });

  await userEvent.click(within(frame).getByRole('button', { name: 'Table' }));

  return frame;
}

describe('what has actually been seen', () => {
  it('tells never-seen apart from not-counted', async () => {
    await renderWithProviders(
      <ObservedEventsChart
        definitions={[
          definition({ id: 'a', name: 'Seen often', observed_count: 42 }),
          definition({
            id: 'b',
            name: 'Defined but silent',
            observed_count: 0,
            last_seen_date: null,
          }),
          definition({
            id: 'c',
            name: 'Never asked about',
            observed_count: null,
            last_seen_date: null,
          }),
        ]}
        windowDays={28}
      />,
    );

    const frame = await openTable();

    /*
     * Three states, three different facts. Zero means the event was looked for and has not
     * happened — usually the most actionable row in the product, because somebody has declared
     * what a sale looks like and nothing has ever matched it. Null means nobody looked, which
     * says nothing at all about whether tracking works.
     */
    expect(
      within(within(frame).getByRole('row', { name: /Seen often/ })).getByText('42'),
    ).toBeInTheDocument();
    expect(
      within(within(frame).getByRole('row', { name: /Defined but silent/ })).getByText(
        'Never seen',
      ),
    ).toBeInTheDocument();
    expect(
      within(within(frame).getByRole('row', { name: /Never asked about/ })).getByText(
        'Not counted',
      ),
    ).toBeInTheDocument();
  });

  it('puts the findings first', async () => {
    await renderWithProviders(
      <ObservedEventsChart
        definitions={[
          definition({ id: 'a', name: 'Busiest', observed_count: 900 }),
          definition({ id: 'b', name: 'Silent', observed_count: 0, last_seen_date: null }),
        ]}
        windowDays={28}
      />,
    );

    const frame = await openTable();
    const rows = within(frame).getAllByRole('row').slice(1);

    // A never-seen conversion buried under the busiest events is a finding nobody acts on.
    expect(rows[0]).toHaveTextContent('Silent');
  });

  it('marks an intent signal as one and never totals it with a conversion', async () => {
    await renderWithProviders(
      <ObservedEventsChart
        definitions={[
          definition({ id: 'a', name: 'Enquiry', observed_count: 10 }),
          definition({
            id: 'b',
            name: 'WhatsApp tap',
            observed_count: 480,
            is_conversion: false,
            is_primary: false,
            is_intent_signal_only: true,
          }),
        ]}
        windowDays={28}
      />,
    );

    const frame = await openTable();

    /*
     * Both are in the chart because somebody defined both. The one thing it must not do is let
     * them add up: a tap is not a conversation, and 490 is not a number about anything.
     */
    expect(
      within(within(frame).getByRole('row', { name: /WhatsApp tap/ })).getByText('Intent only'),
    ).toBeInTheDocument();
    expect(within(frame).queryByText('490')).not.toBeInTheDocument();
  });

  it('says what it cannot say', async () => {
    await renderWithProviders(<ObservedEventsChart definitions={[definition()]} windowDays={28} />);

    const frame = await screen.findByRole('region', { name: 'What has actually been seen' });

    expect(within(frame).getByText(/a tap is not a conversation/)).toBeInTheDocument();
  });

  it('says there is nothing to show rather than drawing an empty grid', async () => {
    await renderWithProviders(<ObservedEventsChart definitions={[]} windowDays={28} />);

    expect(await screen.findByText('Nothing defined yet')).toBeInTheDocument();
  });
});
