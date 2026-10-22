import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useClientsQuery } from '@/features/clients';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { useNotificationPreferencesQuery, useSavePreferencesMutation } from './useNotifications';
import type {
  DigestChannelValue,
  NotificationCategoryValue,
  NotificationPreferences,
  NotificationPreferencesInput,
} from './types';

const CATEGORIES: NotificationCategoryValue[] = [
  'performance',
  'budget',
  'creative',
  'tracking',
  'approvals',
  'reports',
  'work',
];

/** The zones an agency in this market is likely to be in, plus the one the browser is in. */
const ZONES = ['Asia/Riyadh', 'Asia/Dubai', 'Africa/Cairo', 'Europe/London', 'UTC'];

/**
 * What a person wants told, where, when and about what.
 *
 * Channels say plainly what they can do today: in the app works; email and WhatsApp are offered so the choice is
 * recorded, and each carries the product's own word for a thing built and not yet connected. Nothing here can widen
 * what a digest shows — the server re-checks scope — only switch things off or narrow them.
 */
export function DigestSettings() {
  const preferences = useNotificationPreferencesQuery();

  /* Mounted once the server has answered, so the form reads its starting values once rather than syncing them. */
  if (preferences.data === undefined) {
    return null;
  }

  return <SettingsForm initial={preferences.data} />;
}

function SettingsForm({ initial }: { initial: NotificationPreferences }) {
  const { t } = useTranslation();
  const save = useSavePreferencesMutation();
  const [draft, setDraft] = useState<NotificationPreferencesInput>(() => ({
    daily: initial.daily,
    weekly: initial.weekly,
    monthly: initial.monthly,
    channels: initial.channels,
    timezone: initial.timezone,
    hour: initial.hour,
    client_id: initial.client_id,
    project_ids: initial.project_ids,
    muted_categories: initial.muted_categories,
  }));
  const [clientLabel, setClientLabel] = useState<string | null>(null);
  const [clientTerm, setClientTerm] = useState('');
  const clients = useClientsQuery({ search: clientTerm, per_page: 8 });

  const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
  const zones = [...new Set([...ZONES, browserZone, draft.timezone ?? ''])].filter(
    (zone) => zone !== '',
  );

  const update = (patch: Partial<NotificationPreferencesInput>): void =>
    setDraft((current) => ({ ...current, ...patch }));
  const toggleChannel = (channel: DigestChannelValue): void =>
    update({
      channels: draft.channels.includes(channel)
        ? draft.channels.filter((entry) => entry !== channel)
        : [...draft.channels, channel],
    });
  const toggleMuted = (category: NotificationCategoryValue): void =>
    update({
      muted_categories: draft.muted_categories.includes(category)
        ? draft.muted_categories.filter((entry) => entry !== category)
        : [...draft.muted_categories, category],
    });
  const error =
    save.error instanceof ApiError && save.error.message !== '' ? save.error.message : null;

  return (
    <details className="border-line-subtle rounded-2xl border p-4">
      <summary className="text-content cursor-pointer text-sm font-medium">
        {t('notifications.settings.title')}
      </summary>

      <form
        className="mt-4 flex flex-col gap-4"
        onSubmit={(event) => {
          event.preventDefault();
          save.mutate(draft);
        }}
      >
        <ChoiceGroup label={t('notifications.settings.digests')}>
          <div className="flex flex-wrap gap-2">
            {(['daily', 'weekly', 'monthly'] as const).map((cadence) => (
              <Choice
                key={cadence}
                isSelected={draft[cadence]}
                onSelect={() => update({ [cadence]: !draft[cadence] })}
              >
                {t(`notifications.settings.cadence.${cadence}`)}
              </Choice>
            ))}
          </div>
        </ChoiceGroup>

        <ChoiceGroup
          label={t('notifications.settings.channels')}
          hint={t('notifications.settings.channelsHint')}
        >
          <div className="flex flex-wrap gap-2">
            {initial.channel_availability.map((channel) => (
              <span key={channel.channel} className="inline-flex items-center gap-1.5">
                <Choice
                  isSelected={draft.channels.includes(channel.channel)}
                  onSelect={() => toggleChannel(channel.channel)}
                >
                  {t(`notifications.settings.channel.${channel.channel}`)}
                </Choice>
                {channel.availability === 'awaiting_credentials' ? (
                  <Badge tone="warning">{t('notifications.settings.awaitingCredentials')}</Badge>
                ) : null}
              </span>
            ))}
          </div>
        </ChoiceGroup>

        <div className="flex flex-wrap items-end gap-3">
          <div className="flex flex-col gap-1">
            <label htmlFor="digest-timezone" className="text-content-secondary text-xs font-medium">
              {t('notifications.settings.timezone')}
            </label>
            <select
              id="digest-timezone"
              value={draft.timezone ?? ''}
              onChange={(event) =>
                update({ timezone: event.target.value === '' ? null : event.target.value })
              }
              className="bg-surface-sunken text-content h-10 rounded-full border-0 px-4 text-sm"
            >
              <option value="">{t('notifications.settings.workspaceZone')}</option>
              {zones.map((zone) => (
                <option key={zone} value={zone}>
                  {zone}
                </option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor="digest-hour" className="text-content-secondary text-xs font-medium">
              {t('notifications.settings.hour')}
            </label>
            <select
              id="digest-hour"
              value={draft.hour}
              onChange={(event) => update({ hour: Number(event.target.value) })}
              className="bg-surface-sunken text-content h-10 rounded-full border-0 px-4 text-sm"
            >
              {Array.from({ length: 24 }, (_, hour) => (
                <option key={hour} value={hour}>
                  {String(hour).padStart(2, '0')}:00
                </option>
              ))}
            </select>
          </div>
          <EntityPicker
            label={t('notifications.settings.client')}
            clearLabel={t('notifications.settings.clear')}
            value={
              draft.client_id === null
                ? null
                : {
                    id: draft.client_id,
                    label: clientLabel ?? t('notifications.settings.chosenClient'),
                  }
            }
            onChange={(option) => {
              setClientLabel(option?.label ?? null);
              update({ client_id: option === null ? null : option.id });
            }}
            onSearch={setClientTerm}
            options={(clients.data?.data ?? []).map((c) => ({ id: c.id, label: c.name }))}
            isLoading={clients.isFetching}
          />
        </div>

        <ChoiceGroup
          label={t('notifications.settings.muted')}
          hint={t('notifications.settings.mutedHint')}
        >
          <div className="flex flex-wrap gap-2">
            {CATEGORIES.map((category) => (
              <Choice
                key={category}
                isSelected={draft.muted_categories.includes(category)}
                onSelect={() => toggleMuted(category)}
              >
                {t(`notifications.tabs.${category}`)}
              </Choice>
            ))}
          </div>
        </ChoiceGroup>

        {error === null ? null : <Alert tone="danger">{error}</Alert>}
        {save.isSuccess ? (
          <p className="text-success text-sm" role="status">
            {t('notifications.settings.saved')}
          </p>
        ) : null}

        <div>
          <Button type="submit" disabled={save.isPending}>
            {t('notifications.settings.save')}
          </Button>
        </div>
      </form>
    </details>
  );
}
