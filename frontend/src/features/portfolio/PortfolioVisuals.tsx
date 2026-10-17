import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { cn } from '@/shared/lib/cn';
import { formatMinor } from '@/shared/lib/format';
import { Choice } from '@/shared/ui/choice';
import { HealthPill } from '@/shared/ui/health';
import type { HealthState } from '@/shared/ui/health.model';
import type { Portfolio, PortfolioClient, PortfolioProject } from './types';

/** The worst state present, so one tile or row says one thing. */
function worst(h: PortfolioClient['campaign_health']): HealthState {
  if (h.critical > 0) {
    return 'critical';
  }

  if (h.needs_attention > 0) {
    return 'attention';
  }

  if (h.watch > 0) {
    return 'watch';
  }

  if (h.healthy > 0) {
    return 'healthy';
  }

  return h.insufficient_data > 0 ? 'unknown' : 'inactive';
}

/**
 * Clients ranked by what they spend, as bars.
 *
 * Ranked within **one currency at a time** — the tabs are the currencies present — because bars of two units on
 * one scale would rank a client by the exchange rate it happened to be paid in.
 */
export function ClientRanking({
  clients,
  locale,
}: {
  clients: Portfolio['clients'];
  locale: string;
}) {
  const { t } = useTranslation();
  const currencies = [...new Set(clients.flatMap((c) => c.spend.map((s) => s.currency)))];
  const [picked, setPicked] = useState<string | null>(null);
  const currency =
    picked !== null && currencies.includes(picked) ? picked : (currencies[0] ?? null);

  if (currency === null) {
    return <p className="text-content-tertiary text-sm">{t('portfolio.noSpend')}</p>;
  }

  const ranked = clients
    .map((c) => ({ client: c, minor: c.spend.find((s) => s.currency === currency)?.minor ?? 0 }))
    .filter((r) => r.minor > 0)
    .sort((a, b) => b.minor - a.minor)
    .slice(0, 8);
  const top = Math.max(1, ranked[0]?.minor ?? 1);

  return (
    <div className="flex flex-col gap-3">
      {currencies.length > 1 ? (
        <div className="flex gap-2" role="group" aria-label={t('portfolio.currency')}>
          {currencies.map((c) => (
            <Choice key={c} isSelected={c === currency} onSelect={() => setPicked(c)}>
              {c}
            </Choice>
          ))}
        </div>
      ) : null}

      <ol className="flex flex-col gap-3">
        {ranked.map(({ client, minor }, index) => (
          <li key={client.id} className="flex flex-col gap-1.5">
            <div className="flex items-center justify-between gap-3 text-sm">
              <Link
                to={`/clients/${client.id}`}
                className="text-content hover:text-accent flex min-w-0 items-center gap-2 font-medium hover:underline"
              >
                <span className="text-content-tertiary ds-numeric w-5 text-xs">{index + 1}</span>
                <span dir="auto" className="min-w-0 text-start break-words">
                  {client.name}
                </span>
              </Link>
              <span className="flex shrink-0 items-center gap-2">
                <HealthPill state={worst(client.campaign_health)} />
                <span className="ds-numeric">{formatMinor(minor, currency, locale)}</span>
              </span>
            </div>
            <span
              aria-hidden
              className="bg-surface-sunken block h-1.5 overflow-hidden rounded-full"
            >
              <span
                className="bg-accent block h-full rounded-full"
                style={{ width: `${String(Math.max(2, (minor / top) * 100))}%` }}
              />
            </span>
          </li>
        ))}
      </ol>
    </div>
  );
}

const TILE: Record<HealthState, string> = {
  critical: 'border-danger/40 bg-danger-surface',
  attention: 'border-warning/40 bg-warning-surface',
  watch: 'border-warning/30 bg-warning-surface/60',
  healthy: 'border-success/30 bg-success-surface',
  unknown: 'border-line bg-surface-sunken',
  inactive: 'border-line-subtle bg-surface-sunken',
};

/**
 * Every project as one tile, coloured by its worst campaign, worst first.
 *
 * A matrix rather than a table: the question is "where is the red", and a hundred and fifty projects answer it
 * as a field of tiles faster than as rows. Each tile is a link to the project.
 */
export function ProjectMatrix({ projects }: { projects: PortfolioProject[] }) {
  const { t } = useTranslation();
  const rank: HealthState[] = ['critical', 'attention', 'watch', 'unknown', 'healthy', 'inactive'];
  const tiles = projects
    .map((p) => ({ project: p, state: worst(p.campaign_health) }))
    .sort(
      (a, b) =>
        rank.indexOf(a.state) - rank.indexOf(b.state) ||
        a.project.name.localeCompare(b.project.name),
    )
    .slice(0, 60);

  if (tiles.length === 0) {
    return <p className="text-content-tertiary text-sm">{t('portfolio.noProjects')}</p>;
  }

  return (
    <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
      {tiles.map(({ project, state }) => (
        <li key={project.id}>
          <Link
            to={`/projects/${project.id}`}
            className={cn(
              'focus-visible:ring-accent/40 flex h-full flex-col gap-1 rounded-xl border p-3 text-sm transition-shadow hover:shadow-sm focus-visible:ring-2 focus-visible:outline-none',
              TILE[state],
            )}
          >
            <span className="text-content-tertiary truncate text-[11px]" dir="auto">
              {project.client_name ?? ''}
            </span>
            <span className="text-content line-clamp-2 font-medium" dir="auto">
              {project.name}
            </span>
            <span className="text-content-secondary text-xs">
              {t(`health.${state}`)} · {t('portfolio.campaignsCount', { count: project.campaigns })}
            </span>
          </Link>
        </li>
      ))}
    </ul>
  );
}
