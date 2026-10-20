import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api } from '@/shared/api/client';

type Dot = { state: 'green' | 'amber' | 'red' | 'grey'; label: string; sha: string | null };
type SyncStatus = { main: Dot; deploy: Dot };

const COLOUR: Record<Dot['state'], string> = {
  green: 'var(--ds-success-500)',
  amber: 'var(--ds-warning-500)',
  red: 'var(--ds-danger-500)',
  grey: 'var(--ds-neutral-400)',
};

/**
 * Is `main` green, and is that what is live — three dots, no log, no token, no run id.
 *
 * Fetched once when the panel opens and cached by the server for five minutes: no polling. Colour is never the
 * only carrier — every dot is followed by its word. Internal only: it lives in Advanced diagnostics, which needs
 * `workspaces.view`, and the endpoint enforces the same.
 */
export function SyncIndicator() {
  const { t } = useTranslation();
  const status = useQuery({
    queryKey: ['system', 'sync-status'],
    /* A status panel that cannot read says so; it is not an error the page should surface or retry. */
    queryFn: async (): Promise<SyncStatus | null> => {
      try {
        return (await api.get<{ data: SyncStatus }>('/system/sync-status')).data.data;
      } catch {
        return null;
      }
    },
    staleTime: 300_000,
    retry: false,
  });

  const items: Array<{ key: string; dot: Dot }> =
    status.data === undefined || status.data === null
      ? []
      : [
          { key: 'main', dot: status.data.main },
          { key: 'deploy', dot: status.data.deploy },
        ];

  return (
    <ul
      aria-label={t('administration.sync.label')}
      className="bg-surface flex flex-wrap items-center gap-x-6 gap-y-2 rounded-2xl px-4 py-3 text-sm shadow-xs"
    >
      {status.data === null ? (
        <li className="text-content-tertiary flex items-center gap-2">
          <span aria-hidden className="size-2.5 rounded-full" style={{ background: COLOUR.grey }} />
          {t('administration.sync.unavailable')}
        </li>
      ) : (
        items.map(({ key, dot }) => (
          <li key={key} className="text-content flex items-center gap-2">
            <span
              aria-hidden
              className="size-2.5 rounded-full"
              style={{ background: COLOUR[dot.state] }}
            />
            <span className="text-content-secondary">{t(`administration.sync.${key}`)}</span>
            <span className="font-medium">{dot.label}</span>
            {dot.sha === null ? null : (
              <span className="text-content-tertiary ds-numeric font-mono text-xs">{dot.sha}</span>
            )}
          </li>
        ))
      )}
    </ul>
  );
}
