import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { DigestSettings } from './DigestSettings';
import type { NotificationPreferences, NotificationPreferencesInput } from './types';

const state = vi.hoisted(() => ({
  saved: null as NotificationPreferencesInput | null,
  snoozedFor: undefined as number | null | undefined,
  preferences: {
    daily: true,
    weekly: true,
    monthly: true,
    channels: ['in_app'],
    timezone: null,
    hour: 9,
    client_id: null,
    project_ids: null,
    muted_categories: [],
    quiet_from: null,
    quiet_until: null,
    snoozed_until: null,
    channel_availability: [{ channel: 'in_app', label: 'In the app', availability: 'available' }],
  } as unknown as NotificationPreferences,
}));

vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({ data: { data: [] }, isFetching: false }),
}));

vi.mock('./api', () => ({
  fetchNotificationPreferences: () => Promise.resolve(state.preferences),
  saveNotificationPreferences: (input: NotificationPreferencesInput) => {
    state.saved = input;

    return Promise.resolve({ ...state.preferences, ...input });
  },
  snoozeNotifications: (minutes: number) => {
    state.snoozedFor = minutes;

    return Promise.resolve({ ...state.preferences, snoozed_until: '2026-11-06T18:00:00+00:00' });
  },
  endNotificationSnooze: () => {
    state.snoozedFor = null;

    return Promise.resolve({ ...state.preferences, snoozed_until: null });
  },
}));

describe('DigestSettings — quiet hours and snooze', () => {
  beforeEach(() => {
    state.saved = null;
    state.snoozedFor = undefined;
    state.preferences = {
      ...state.preferences,
      quiet_from: null,
      quiet_until: null,
      snoozed_until: null,
    };
  });

  it('saves a quiet window as two hours, and never sends a snooze with the form', async () => {
    await renderWithProviders(<DigestSettings />);

    await userEvent.click(await screen.findByText('Digest settings'));
    await userEvent.click(screen.getByRole('button', { name: 'No quiet hours' }));
    await userEvent.selectOptions(screen.getByLabelText('From'), '23');
    await userEvent.selectOptions(screen.getByLabelText('Until'), '6');
    await userEvent.click(screen.getByRole('button', { name: 'Save settings' }));

    await waitFor(() => expect(state.saved).not.toBeNull());
    expect(state.saved?.quiet_from).toBe(23);
    expect(state.saved?.quiet_until).toBe(6);
    expect(state.saved).not.toHaveProperty('snoozed_until');
  });

  it('snoozes on one press, says until when, and offers only the way out while snoozed', async () => {
    await renderWithProviders(<DigestSettings />);

    await userEvent.click(await screen.findByRole('button', { name: '4 hours' }));

    expect(await screen.findByText(/Snoozed until/)).toBeInTheDocument();
    expect(state.snoozedFor).toBe(240);
    expect(screen.queryByRole('button', { name: '1 hour' })).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'End snooze' }));

    await waitFor(() => expect(state.snoozedFor).toBeNull());
    expect(await screen.findByRole('button', { name: '1 hour' })).toBeInTheDocument();
  });
});
