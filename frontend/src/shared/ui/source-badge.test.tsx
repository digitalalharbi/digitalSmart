import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { SourceBadge, SourceNotice } from './source-badge';

describe('SourceBadge', () => {
  it('stays out of the way for live data', async () => {
    const { container } = await renderWithProviders(<SourceBadge source="live" />);

    // The absence of a badge is itself the statement: everything else is labelled.
    expect(container).toBeEmptyDOMElement();
  });

  it('labels every state that is not live', async () => {
    for (const source of ['stale', 'partial', 'sandbox', 'manual', 'estimated'] as const) {
      const { unmount } = await renderWithProviders(<SourceBadge source={source} />);
      expect(screen.getByText(/.+/)).toBeInTheDocument();
      unmount();
    }
  });
});

describe('SourceNotice', () => {
  it('explains a sandbox surface before the reader sees the numbers', async () => {
    await renderWithProviders(<SourceNotice source="sandbox" />);

    const notice = screen.getByRole('status');

    expect(notice).toHaveTextContent('Sandbox');
    // The warning that actually matters: do not give these to a client.
    expect(notice).toHaveTextContent(/Do not share them with a client/i);
  });

  it('explains that a partial sync understates the totals', async () => {
    await renderWithProviders(<SourceNotice source="partial" />);

    expect(screen.getByRole('status')).toHaveTextContent(/lower than the real ones/i);
  });

  it('says nothing for live or synced data', async () => {
    const live = await renderWithProviders(<SourceNotice source="live" />);
    expect(live.container).toBeEmptyDOMElement();
    live.unmount();

    const synced = await renderWithProviders(<SourceNotice source="synced" />);
    expect(synced.container).toBeEmptyDOMElement();
  });
});
