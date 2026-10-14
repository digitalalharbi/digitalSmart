import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { ContextDrawer } from './context-drawer';

function Harness({ description }: { description?: string }) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>
        Open lead
      </button>
      <ContextDrawer
        open={open}
        onOpenChange={setOpen}
        title="Noura Al-Qahtani"
        {...(description === undefined ? {} : { description })}
      >
        <p>Lead detail</p>
      </ContextDrawer>
    </>
  );
}

describe('ContextDrawer', () => {
  it('opens over the page and closes back to it', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<Harness />);

    await user.click(screen.getByRole('button', { name: 'Open lead' }));
    const drawer = await screen.findByRole('dialog', { name: 'Noura Al-Qahtani' });
    expect(drawer).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Close' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  /*
   * No description is better than an empty one: pointing `aria-describedby` at a blank element promises a
   * screen-reader user a sentence and then reads them nothing.
   */
  it('claims a description only when it has one', async () => {
    const user = userEvent.setup();
    const { rerender } = await renderWithProviders(<Harness />);

    await user.click(screen.getByRole('button', { name: 'Open lead' }));
    expect(screen.getByRole('dialog')).not.toHaveAttribute('aria-describedby');

    rerender(<Harness description="Qualified three days ago." />);
    expect(screen.getByRole('dialog')).toHaveAccessibleDescription('Qualified three days ago.');
  });

  it('returns focus to the control that opened it', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<Harness />);

    const trigger = screen.getByRole('button', { name: 'Open lead' });
    await user.click(trigger);
    await user.keyboard('{Escape}');

    /* Radix restores focus on the close animation frame, so this settles rather than asserts immediately. */
    await waitFor(() => {
      expect(trigger).toHaveFocus();
    });
  });
});
