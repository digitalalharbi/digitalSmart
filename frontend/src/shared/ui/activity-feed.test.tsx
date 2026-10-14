import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { ActivityFeed, type ActivityEntry } from './activity-feed';

function entry(overrides: Partial<ActivityEntry> = {}): ActivityEntry {
  return {
    id: 'e1',
    actor: 'Layla Nasser',
    summary: 'Moved Ramadan Push to Review.',
    at: new Date(Date.now() - 3_600_000).toISOString(),
    ...overrides,
  };
}

describe('ActivityFeed', () => {
  it('attributes each entry to who did it and when', async () => {
    await renderWithProviders(<ActivityFeed entries={[entry()]} />);

    expect(screen.getByText('Moved Ramadan Push to Review.')).toBeInTheDocument();
    expect(screen.getByText(/Layla Nasser/)).toBeInTheDocument();
    expect(screen.getByText('1 hour ago')).toBeInTheDocument();
  });

  /*
   * A scheduled sync is not something a person did. Attributing it to the last human who touched the record
   * is the failure this distinction exists to prevent.
   */
  it('attributes an unattended change to the system rather than to a person', async () => {
    await renderWithProviders(<ActivityFeed entries={[entry({ actor: null })]} />);

    expect(screen.getByText(/System/)).toBeInTheDocument();
  });

  it('links an entry only when there is somewhere to go', async () => {
    await renderWithProviders(
      <ActivityFeed entries={[entry({ id: 'e1' }), entry({ id: 'e2', to: '/campaigns/c1' })]} />,
    );

    expect(screen.getAllByRole('link')).toHaveLength(1);
  });

  it('says so plainly when there is no history', async () => {
    await renderWithProviders(<ActivityFeed entries={[]} />);

    expect(screen.getByText('Nothing yet')).toBeInTheDocument();
  });
});
