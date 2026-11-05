import { fireEvent, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { ReportBuilder } from './ReportBuilder';
import type { ReportDraft, SectionDraft } from './types';

/*
 * Hiding a section is a switch on the section, sent to the server, which is what leaves it out of every render. The
 * builder's only rule of its own is that the last visible section cannot be hidden — the server refuses that report.
 */

function section(title: string, hidden = false): SectionDraft {
  return {
    kind: 'scorecard',
    title,
    metrics: ['spend'],
    dimension: null,
    row_limit: null,
    hidden,
  };
}

function draft(sections: SectionDraft[]): ReportDraft {
  return {
    id: 'r1',
    name: 'Monthly performance',
    description: null,
    client_id: null,
    window: 'last_30_days',
    custom_from: null,
    custom_until: null,
    comparison: 'none',
    audience: 'internal',
    intent: 'detailed',
    internal_note: null,
    filters: { providers: [], campaign_ids: [], project_ids: [], client_id: null },
    sections,
  } as unknown as ReportDraft;
}

async function renderBuilder(sections: SectionDraft[]) {
  const onSubmit = vi.fn();

  await renderWithProviders(
    <ReportBuilder
      vocabulary={undefined}
      clients={[]}
      onSearchClients={() => undefined}
      draft={draft(sections)}
      isSaving={false}
      error={null}
      onSubmit={onSubmit}
      onCancel={() => undefined}
    />,
  );

  return onSubmit;
}

describe('ReportBuilder — hiding a section', () => {
  it('sends the hidden flag for the section that was switched off, and says where it will not appear', async () => {
    const onSubmit = await renderBuilder([section('Headline'), section('Internal check')]);

    const toggles = screen.getAllByRole('button', { name: 'Hide this section' });
    expect(toggles).toHaveLength(2);
    expect(toggles[1]).toHaveAttribute('aria-pressed', 'false');

    fireEvent.click(toggles[1]!);

    expect(screen.getAllByRole('button', { name: 'Hide this section' })[1]).toHaveAttribute(
      'aria-pressed',
      'true',
    );
    expect(screen.getByText(/shown nowhere/)).toBeInTheDocument();

    fireEvent.submit(screen.getByRole('button', { name: /save/i }).closest('form')!);

    const sent = onSubmit.mock.calls[0]?.[0] as { sections: SectionDraft[] };
    expect(sent.sections.map((entry) => entry.hidden === true)).toEqual([false, true]);
  });

  it('does not let the last visible section be hidden', async () => {
    await renderBuilder([section('Headline'), section('Internal check', true)]);

    const toggles = screen.getAllByRole('button', { name: 'Hide this section' });
    expect(toggles[0]).toBeDisabled();
    expect(toggles[1]).toBeEnabled();
  });
});
