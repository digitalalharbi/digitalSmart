import { beforeEach, describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { useUiStore } from '@/shared/store/ui-store';
import { MobileNavDrawer } from './MobileNavDrawer';

describe('MobileNavDrawer', () => {
  beforeEach(() => {
    useUiStore.setState({ isMobileNavOpen: false, locale: 'en', theme: 'light' });
  });

  it('renders nothing while closed', async () => {
    await renderWithProviders(<MobileNavDrawer />);

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('exposes the navigation as a modal dialog when open', async () => {
    useUiStore.setState({ isMobileNavOpen: true });
    await renderWithProviders(<MobileNavDrawer />);

    const dialog = screen.getByRole('dialog');
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(screen.getByRole('link', { name: 'Overview' })).toBeInTheDocument();
  });

  it('closes on Escape', async () => {
    useUiStore.setState({ isMobileNavOpen: true });
    const user = userEvent.setup();
    await renderWithProviders(<MobileNavDrawer />);

    await user.keyboard('{Escape}');

    expect(useUiStore.getState().isMobileNavOpen).toBe(false);
  });

  it('closes when the backdrop is activated', async () => {
    useUiStore.setState({ isMobileNavOpen: true });
    const user = userEvent.setup();
    await renderWithProviders(<MobileNavDrawer />);

    await user.click(screen.getAllByRole('button', { name: 'Close navigation' })[0]!);

    expect(useUiStore.getState().isMobileNavOpen).toBe(false);
  });

  it('restores background scrolling when it closes', async () => {
    useUiStore.setState({ isMobileNavOpen: true });
    const user = userEvent.setup();
    await renderWithProviders(<MobileNavDrawer />);
    expect(document.body.style.overflow).toBe('hidden');

    await user.keyboard('{Escape}');

    expect(document.body.style.overflow).not.toBe('hidden');
  });
});
