import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { formatMinor, formatNumber } from '@/shared/lib/format';
import { HealthDistribution } from '@/shared/ui/health-distribution';
import type { Money, Portfolio, Roas } from './types';

/**
 * A headline figure, shortened: 13.4M, not 13,430,943.38.
 *
 * Exact amounts belong in a table or a drill-down; on a card the reader wants magnitude, and a long figure in
 * a narrow card is how a number ends up clipped. The currency stays attached, so it is still never a bare number.
 */
function compactMoney(minor: number, currency: string, locale: string): string {
  const probe = new Intl.NumberFormat(locale, { style: 'currency', currency });
  const exponent = probe.resolvedOptions().maximumFractionDigits ?? 2;

  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    currencyDisplay: 'narrowSymbol',
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(minor / 10 ** exponent);
}

/** Every currency on its own line. Two currencies are never one number. */
export function MoneyLines({
  values,
  locale,
  compact = false,
}: {
  values: Money[];
  locale: string;
  /** Shortened for a card. The exact figure is the title, so nothing is lost to a hover. */
  compact?: boolean;
}) {
  if (values.length === 0) {
    return <span className="text-content-tertiary">—</span>;
  }

  return (
    <span className="flex min-w-0 flex-col">
      {values.map((m) => (
        <span
          key={m.currency}
          className="ds-numeric break-words"
          title={compact ? formatMinor(m.minor, m.currency, locale) : undefined}
        >
          {compact
            ? compactMoney(m.minor, m.currency, locale)
            : formatMinor(m.minor, m.currency, locale)}
        </span>
      ))}
    </span>
  );
}

export function RoasLines({ values }: { values: Roas[] }) {
  if (values.length === 0) {
    return <span className="text-content-tertiary">—</span>;
  }

  return (
    <span className="flex flex-col">
      {values.map((r) => (
        <span key={r.currency} className="ds-numeric">
          {r.value.toFixed(2)}×{values.length > 1 ? ` ${r.currency}` : ''}
        </span>
      ))}
    </span>
  );
}

export function Kpi({
  label,
  children,
  to,
}: {
  label: string;
  children: React.ReactNode;
  /** Where the evidence behind this figure lives. A figure with nowhere to go is a figure to take on trust. */
  to?: string;
}) {
  const body = (
    <>
      <span className="ds-overline text-content-tertiary truncate">{label}</span>
      <span className="ds-figure text-content min-w-0 text-lg sm:text-xl">{children}</span>
    </>
  );
  const card =
    'bg-surface border-line-subtle flex min-w-0 flex-col gap-1.5 rounded-2xl border p-4 shadow-xs';

  return to === undefined ? (
    <div className={card}>{body}</div>
  ) : (
    <Link
      to={to}
      className={`${card} hover:border-line-strong focus-visible:ring-accent/40 transition-shadow hover:shadow-sm focus-visible:ring-2 focus-visible:outline-none`}
    >
      {body}
    </Link>
  );
}

/** "Not shown to your role" is a different statement from zero, and is drawn as one. */
export function Count({ value, locale }: { value: number | null; locale: string }) {
  const { t } = useTranslation();

  return value === null ? (
    <span className="text-content-tertiary text-sm" title={t('portfolio.notShown')}>
      —
    </span>
  ) : (
    <>{formatNumber(value, locale)}</>
  );
}

/**
 * The agency's headline figures. With `drill`, each is a way into what stands behind it — spend into the campaigns
 * that spent it, leads into the lead centre, sales into the stores — so no figure on the first screen is a dead end.
 * Left off on a client's own page, where the module it opens would be the agency's and not that client's.
 */
export function KpiGrid({
  data,
  locale,
  drill = false,
}: {
  data: Portfolio['kpis'];
  locale: string;
  drill?: boolean;
}) {
  const { t } = useTranslation();

  return (
    <section
      aria-label={t('portfolio.kpis')}
      className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5"
    >
      <Kpi label={t('portfolio.kpi.spend')} {...(drill ? { to: '/campaigns' } : {})}>
        <MoneyLines compact values={data.spend} locale={locale} />
      </Kpi>
      <Kpi label={t('portfolio.kpi.revenue')} {...(drill ? { to: '/analytics' } : {})}>
        <MoneyLines compact values={data.platform_revenue} locale={locale} />
      </Kpi>
      <Kpi label={t('portfolio.kpi.storeRevenue')} {...(drill ? { to: '/commerce' } : {})}>
        <MoneyLines compact values={data.store_revenue} locale={locale} />
      </Kpi>
      <Kpi label="ROAS" {...(drill ? { to: '/analytics' } : {})}>
        <RoasLines values={data.roas} />
      </Kpi>
      <Kpi label={t('portfolio.kpi.leads')} {...(drill ? { to: '/leads' } : {})}>
        <Count value={data.leads} locale={locale} />
      </Kpi>
      <Kpi label={t('portfolio.kpi.qualified')} {...(drill ? { to: '/leads' } : {})}>
        <Count value={data.qualified_leads} locale={locale} />
      </Kpi>
      <Kpi label={t('portfolio.kpi.sales')} {...(drill ? { to: '/commerce' } : {})}>
        <Count value={data.sales} locale={locale} />
      </Kpi>
      <Kpi label={t('portfolio.kpi.clients')} {...(drill ? { to: '/clients' } : {})}>
        <Count value={data.active_clients} locale={locale} />
      </Kpi>
      <Kpi label={t('portfolio.kpi.projects')} {...(drill ? { to: '/projects' } : {})}>
        <Count value={data.active_projects} locale={locale} />
      </Kpi>
      <Kpi label={t('portfolio.kpi.campaigns')} {...(drill ? { to: '/campaigns' } : {})}>
        <Count value={data.active_campaigns} locale={locale} />
      </Kpi>
    </section>
  );
}

const ORDER = [
  'critical',
  'needs_attention',
  'watch',
  'healthy',
  'insufficient_data',
  'inactive',
] as const;

export function HealthSection({ health }: { health: Portfolio['health'] }) {
  const { t } = useTranslation();

  return (
    <HealthDistribution
      counts={health}
      order={[...ORDER]}
      labels={{
        critical: t('health.critical'),
        needs_attention: t('health.attention'),
        watch: t('health.watch'),
        healthy: t('health.healthy'),
        insufficient_data: t('health.unknown'),
        inactive: t('health.inactive'),
      }}
    />
  );
}

export function PlatformMix({ mix, locale }: { mix: Portfolio['platform_mix']; locale: string }) {
  const { t } = useTranslation();

  if (mix.length === 0) {
    return <p className="text-content-tertiary text-sm">{t('portfolio.noSpend')}</p>;
  }

  /* One currency's share at a time: the largest currency drives the bar and the figures are listed in full. */
  const first = mix.flatMap((m) => m.spend)[0]?.currency ?? null;
  const largest = Math.max(
    1,
    ...mix.map((m) => m.spend.find((s) => s.currency === first)?.minor ?? 0),
  );

  return (
    <ul className="flex flex-col gap-3">
      {mix.map((m) => {
        const base = m.spend.find((s) => s.currency === first)?.minor ?? 0;

        return (
          <li key={m.provider} className="flex flex-col gap-1.5">
            <div className="flex items-center justify-between gap-3 text-sm">
              <span className="text-content font-medium">{m.provider.replace('_', ' ')}</span>
              <MoneyLines values={m.spend} locale={locale} />
            </div>
            <span
              aria-hidden
              className="bg-surface-sunken block h-1.5 overflow-hidden rounded-full"
            >
              <span
                className="bg-accent block h-full rounded-full"
                style={{ width: `${String(Math.max(2, (base / largest) * 100))}%` }}
              />
            </span>
          </li>
        );
      })}
    </ul>
  );
}

export function ClientLink({ id, name }: { id: string; name: string }) {
  return (
    <Link
      to={`/clients/${id}`}
      className="text-content hover:text-content-brand font-medium hover:underline"
    >
      {name}
    </Link>
  );
}
