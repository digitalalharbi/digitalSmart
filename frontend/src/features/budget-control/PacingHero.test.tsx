import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { PacingHero, type PacedRow } from './PacingHero';

const row = (o: Partial<PacedRow> = {}): PacedRow => ({
  currency: 'SAR',
  approved_minor: 1_000_000,
  spent_minor: 600_000,
  pacing: { expected_minor: 500_000, projected_minor: 1_200_000 },
  ...o,
});

describe('PacingHero', () => {
  it('shows plan, pace, actual and forecast per currency, never across them', async () => {
    await renderWithProviders(<PacingHero rows={[row(), row({ currency: 'USD' })]} locale="en" />);

    const regions = screen.getAllByRole('list');

    expect(regions).toHaveLength(2);
    expect(within(regions[0] as HTMLElement).getByText('Projected at the end')).toBeInTheDocument();
  });

  it('leaves out a budget whose spend could not be totalled instead of counting it as zero', async () => {
    const { container } = await renderWithProviders(
      <PacingHero rows={[row({ spent_minor: null, pacing: null })]} locale="en" />,
    );

    expect(container).toBeEmptyDOMElement();
  });

  it('marks a forecast above the approved amount as overspend', async () => {
    await renderWithProviders(<PacingHero rows={[row()]} locale="en" />);

    expect(screen.getByText(/vs expected/)).toBeInTheDocument();
  });
});
