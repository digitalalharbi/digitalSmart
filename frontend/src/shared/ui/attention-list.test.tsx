import { describe, expect, it } from 'vitest';
import { screen, within } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { sortAttention, type AttentionItem } from './attention.model';
import { AttentionList } from './attention-list';

function item(overrides: Partial<AttentionItem> = {}): AttentionItem {
  return {
    id: 'a1',
    severity: 'attention',
    message: 'Ramadan Push will spend its budget four days early.',
    entity: { kind: 'Campaign', label: 'Ramadan Push' },
    owner: 'Layla Nasser',
    since: new Date(Date.now() - 3 * 86_400_000).toISOString(),
    action: { label: 'Open budget', to: '/budgets/b1' },
    ...overrides,
  };
}

describe('AttentionList', () => {
  it('shows the sentence, the entity, the owner and one action', async () => {
    await renderWithProviders(<AttentionList items={[item()]} />);

    const list = screen.getByRole('list', { name: 'Needs attention' });
    expect(
      within(list).getByText('Ramadan Push will spend its budget four days early.'),
    ).toBeInTheDocument();
    expect(within(list).getByText('Campaign')).toBeInTheDocument();
    expect(within(list).getByText('Layla Nasser')).toBeInTheDocument();
    expect(within(list).getByRole('link', { name: /Open budget/ })).toHaveAttribute(
      'href',
      '/budgets/b1',
    );
  });

  /*
   * An item nobody owns is the one most likely to sit untouched, so the absence is printed rather than left
   * as a blank where a name would be.
   */
  it('names the absence of an owner instead of leaving a gap', async () => {
    await renderWithProviders(<AttentionList items={[item({ owner: null })]} />);

    expect(screen.getByText('Unassigned')).toBeInTheDocument();
  });

  it('says so plainly when nothing is waiting', async () => {
    await renderWithProviders(<AttentionList items={[]} />);

    expect(screen.getByText('Nothing needs attention')).toBeInTheDocument();
  });

  it('honours a limit without changing the order it would have shown', async () => {
    await renderWithProviders(
      <AttentionList
        items={[item({ id: 'a1', severity: 'watch' }), item({ id: 'a2', severity: 'critical' })]}
        limit={1}
      />,
    );

    expect(screen.getAllByRole('listitem')).toHaveLength(1);
    /* The critical one survives the cut, not the first one passed in. */
    expect(screen.getByRole('img', { name: 'Critical' })).toBeInTheDocument();
  });
});

describe('sortAttention', () => {
  it('puts the worst first and, within a severity, the oldest first', () => {
    const recent = new Date(Date.now() - 86_400_000).toISOString();
    const old = new Date(Date.now() - 20 * 86_400_000).toISOString();

    const ordered = sortAttention([
      item({ id: 'watch', severity: 'watch', since: old }),
      item({ id: 'recent', severity: 'attention', since: recent }),
      item({ id: 'old', severity: 'attention', since: old }),
      item({ id: 'critical', severity: 'critical', since: recent }),
    ]);

    expect(ordered.map((entry) => entry.id)).toEqual(['critical', 'old', 'recent', 'watch']);
  });

  /* Unknown age is not old age: an item with no date sorts last inside its own severity, not first. */
  it('does not treat a missing date as maximum age', () => {
    const old = new Date(Date.now() - 20 * 86_400_000).toISOString();
    const ordered = sortAttention([
      item({ id: 'undated', since: null }),
      item({ id: 'dated', since: old }),
    ]);

    expect(ordered.map((entry) => entry.id)).toEqual(['dated', 'undated']);
  });
});
