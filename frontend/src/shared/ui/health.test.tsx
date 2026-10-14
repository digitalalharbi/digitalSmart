import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { HEALTH_RANK, type HealthState } from './health.model';
import { HealthDot, HealthPill } from './health';

const ALL: HealthState[] = ['healthy', 'watch', 'attention', 'critical', 'inactive', 'unknown'];

describe('health vocabulary', () => {
  it('gives every state a word, so nothing is carried by colour alone', async () => {
    await renderWithProviders(
      <>
        {ALL.map((state) => (
          <HealthPill key={state} state={state} />
        ))}
      </>,
    );

    for (const label of [
      'Healthy',
      'Watch',
      'Needs attention',
      'Critical',
      'Inactive',
      'Not enough data',
    ]) {
      expect(screen.getByText(label)).toBeInTheDocument();
    }
  });

  /*
   * "Not enough data" is a state, not a gap. A campaign with four days of history has no trend, and showing
   * it as healthy is how a product comes to look confident about something it has not measured.
   */
  it('keeps "not enough data" distinct from healthy', async () => {
    await renderWithProviders(<HealthPill state="unknown" />);

    expect(screen.getByText('Not enough data')).toBeInTheDocument();
    expect(screen.queryByText('Healthy')).not.toBeInTheDocument();
  });

  it('names the dot for a reader who cannot see it', async () => {
    await renderWithProviders(<HealthDot state="critical" />);

    expect(screen.getByRole('img', { name: 'Critical' })).toBeInTheDocument();
  });

  it('ranks the states worst-first, with inactive and unknown below healthy', () => {
    const ordered = [...ALL].sort((a, b) => HEALTH_RANK[a] - HEALTH_RANK[b]);

    expect(ordered).toEqual(['critical', 'attention', 'watch', 'unknown', 'healthy', 'inactive']);
  });
});
