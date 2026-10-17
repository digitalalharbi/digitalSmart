import { useTranslation } from 'react-i18next';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { HealthPill } from '@/shared/ui/health';
import { HEALTH_RANK, type HealthState } from '@/shared/ui/health.model';
import type { Freshness, MeasurementSource } from './types';

/** The technical freshness onto the product's one health vocabulary. `silent` is the worst: it looks fine. */
const STATE: Record<Freshness, HealthState> = {
  live: 'healthy',
  synced: 'healthy',
  stale: 'attention',
  silent: 'critical',
  never_synced: 'unknown',
};

type Group = {
  key: string;
  label: string;
  sources: MeasurementSource[];
  state: HealthState;
  lastEventAt: string | null;
  lastSyncedAt: string | null;
  healthy: number;
};

function newest(values: Array<string | null>): string | null {
  return (
    values
      .filter((v): v is string => v !== null)
      .sort()
      .at(-1) ?? null
  );
}

/**
 * Tracking at a glance: a score, then one card per thing that measures.
 *
 * Grouped by what a person calls it — the website, GA4, Tag Manager, each ad platform, the store, the CRM — and
 * not by how it is wired. Each card says one state, when it last heard something, and how many issues name it.
 * The detail (what is wrong, what to do) is the list below.
 */
export function TrackingScorecards({
  sources,
  locale,
}: {
  sources: MeasurementSource[];
  locale: string;
}) {
  const { t } = useTranslation();
  const active = sources.filter((s) => s.is_active);

  if (active.length === 0) {
    return null;
  }

  const groups = new Map<string, Group>();

  for (const source of active) {
    const key =
      source.kind === 'ad_platform' ? `ad:${source.provider ?? source.name}` : source.kind;
    const label =
      source.kind === 'ad_platform' ? (source.provider_label ?? source.name) : source.kind_label;
    const group = groups.get(key) ?? {
      key,
      label,
      sources: [],
      state: 'healthy' as HealthState,
      lastEventAt: null,
      lastSyncedAt: null,
      healthy: 0,
    };

    group.sources.push(source);
    const state = STATE[source.freshness];
    group.state = HEALTH_RANK[state] < HEALTH_RANK[group.state] ? state : group.state;
    group.lastEventAt = newest([group.lastEventAt, source.last_event_at]);
    group.lastSyncedAt = newest([group.lastSyncedAt, source.last_synced_at]);
    group.healthy += state === 'healthy' ? 1 : 0;
    groups.set(key, group);
  }

  const healthy = active.filter((s) => STATE[s.freshness] === 'healthy').length;
  const score = Math.round((healthy / active.length) * 100);
  const ordered = [...groups.values()].sort((a, b) => HEALTH_RANK[a.state] - HEALTH_RANK[b.state]);

  return (
    <section aria-label={t('tracking.cards.title')} className="flex flex-col gap-4">
      <div className="bg-surface border-line-subtle flex flex-wrap items-center gap-6 rounded-2xl border p-5 shadow-xs">
        <div>
          <p className="ds-overline text-content-tertiary">{t('tracking.cards.score')}</p>
          <p className="ds-figure text-content text-4xl">{score}%</p>
        </div>
        <div
          role="progressbar"
          aria-label={t('tracking.cards.score')}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={score}
          className="bg-surface-sunken h-2.5 min-w-40 flex-1 overflow-hidden rounded-full"
        >
          <div
            className={`h-full rounded-full ${score >= 90 ? 'bg-success' : score >= 60 ? 'bg-warning' : 'bg-danger'}`}
            style={{ width: `${String(score)}%` }}
          />
        </div>
        <p className="text-content-secondary text-sm">
          {t('tracking.cards.summary', { healthy, total: active.length })}
        </p>
      </div>

      <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {ordered.map((group) => (
          <li
            key={group.key}
            className="bg-surface border-line-subtle flex flex-col gap-2 rounded-2xl border p-4 shadow-xs"
          >
            <div className="flex items-center justify-between gap-2">
              <span className="text-content truncate text-sm font-semibold">{group.label}</span>
              <HealthPill state={group.state} />
            </div>
            <dl className="text-content-secondary grid grid-cols-2 gap-1 text-xs">
              <dt>{t('tracking.cards.lastEvent')}</dt>
              <dd className="text-content text-end">
                {group.lastEventAt === null ? '—' : formatRelativeTime(group.lastEventAt, locale)}
              </dd>
              <dt>{t('tracking.cards.lastSync')}</dt>
              <dd className="text-content text-end">
                {group.lastSyncedAt === null ? '—' : formatRelativeTime(group.lastSyncedAt, locale)}
              </dd>
              <dt>{t('tracking.cards.needsAttention')}</dt>
              <dd
                className={`text-end ${group.sources.length > group.healthy ? 'text-danger' : 'text-content'}`}
              >
                {group.sources.length === group.healthy
                  ? t('tracking.cards.none')
                  : t('tracking.cards.ofTotal', {
                      count: group.sources.length - group.healthy,
                      total: group.sources.length,
                    })}
              </dd>
            </dl>
          </li>
        ))}
      </ul>
    </section>
  );
}
