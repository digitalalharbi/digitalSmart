import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { formatMinor } from '@/shared/lib/format';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { Skeleton } from '@/shared/ui/skeleton';
import { useBudgetOverview } from './useRoleData';

/** One headline figure. Several in a row are the top of a dashboard; one alone is a card. */
export function Stat({
  label,
  value,
  tone = 'default',
  to,
}: {
  label: string;
  value: string | number;
  tone?: 'default' | 'danger' | 'warning';
  to?: string;
}) {
  const body = (
    <div className="bg-surface border-line-subtle flex h-full flex-col gap-1 rounded-2xl border p-4 shadow-xs">
      <span className="ds-overline text-content-tertiary">{label}</span>
      <span
        className={`ds-figure text-3xl ${tone === 'danger' ? 'text-danger' : tone === 'warning' ? 'text-warning' : 'text-content'}`}
      >
        {value}
      </span>
    </div>
  );

  return to === undefined ? (
    body
  ) : (
    <Link
      to={to}
      className="focus-visible:ring-accent/40 rounded-2xl focus-visible:ring-2 focus-visible:outline-none"
    >
      {body}
    </Link>
  );
}

export function StatRow({ children, label }: { children: React.ReactNode; label: string }) {
  return (
    <section aria-label={label} className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      {children}
    </section>
  );
}

/** Approved, allocated, spent and what is left — per currency, with the share used as a bar. */
export function BudgetTotalsCards({ locale }: { locale: string }) {
  const { t } = useTranslation();
  const { data, isPending } = useBudgetOverview();

  if (isPending || data === undefined) {
    return <Skeleton className="h-32 w-full" />;
  }

  return (
    <section
      aria-label={t('budgetControl.totals')}
      className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
    >
      {data.meta.totals.map((total) => {
        const used = Math.min(100, (total.spent_minor / Math.max(total.approved_minor, 1)) * 100);
        const left = total.approved_minor - total.spent_minor;

        return (
          <div
            key={total.currency}
            className="bg-surface border-line-subtle flex flex-col gap-3 rounded-2xl border p-4 shadow-xs"
          >
            <div className="flex items-baseline justify-between">
              <span className="ds-overline text-content-tertiary">{total.currency}</span>
              <span className={`ds-figure text-xl ${left < 0 ? 'text-danger' : ''}`}>
                {formatMinor(left, total.currency, locale)}
                <span className="text-content-tertiary ms-1 text-xs font-normal">
                  {t('budgetControl.remaining')}
                </span>
              </span>
            </div>
            <div
              role="progressbar"
              aria-label={t('budgetControl.used', { currency: total.currency })}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={Math.round(used)}
              className="bg-surface-sunken h-2 overflow-hidden rounded-full"
            >
              <div
                className={`h-full rounded-full ${left < 0 ? 'bg-danger' : 'bg-accent'}`}
                style={{ width: `${String(used)}%` }}
              />
            </div>
            <dl className="text-content-secondary flex flex-col gap-1 text-xs">
              <div className="flex items-baseline justify-between gap-3">
                <dt>{t('budgetControl.approved')}</dt>
                <dd className="ds-numeric text-content text-sm">
                  {formatMinor(total.approved_minor, total.currency, locale)}
                </dd>
              </div>
              <div className="flex items-baseline justify-between gap-3">
                <dt>{t('budgetControl.allocated')}</dt>
                <dd className="ds-numeric text-content text-sm">
                  {formatMinor(total.allocated_minor, total.currency, locale)}
                </dd>
              </div>
              <div className="flex items-baseline justify-between gap-3">
                <dt>{t('budgetControl.spent')}</dt>
                <dd className="ds-numeric text-content text-sm">
                  {formatMinor(total.spent_minor, total.currency, locale)}
                </dd>
              </div>
            </dl>
          </div>
        );
      })}
    </section>
  );
}

/** Clients ranked by how many budget risks they carry — where finance should look first. */
export function ClientBudgetRanking() {
  const { t } = useTranslation();
  const { data, isPending } = useBudgetOverview();

  if (isPending || data === undefined) {
    return <Skeleton className="h-32 w-full" />;
  }

  const byClient = new Map<string, { id: string; name: string; risks: number; budgets: number }>();

  for (const row of data.data) {
    const entry = byClient.get(row.client.id) ?? {
      id: row.client.id,
      name: row.client.name,
      risks: 0,
      budgets: 0,
    };
    entry.risks += row.risks.length;
    entry.budgets += 1;
    byClient.set(row.client.id, entry);
  }

  const ranked = [...byClient.values()]
    .filter((c) => c.risks > 0)
    .sort((a, b) => b.risks - a.risks)
    .slice(0, 8);
  const top = Math.max(1, ranked[0]?.risks ?? 1);

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('home.clientBudgetRanking')}</CardTitle>
      </CardHeader>
      <CardContent>
        {ranked.length === 0 ? (
          <p className="text-content-tertiary text-sm">{t('home.noBudgetRisk')}</p>
        ) : (
          <ol className="flex flex-col gap-3">
            {ranked.map((c) => (
              <li key={c.id} className="flex flex-col gap-1.5">
                <div className="flex justify-between text-sm">
                  <Link
                    to={`/clients/${c.id}`}
                    className="text-content hover:text-accent font-medium hover:underline"
                  >
                    {c.name}
                  </Link>
                  <span className="ds-numeric text-content-secondary">
                    {t('home.risksOf', { risks: c.risks, budgets: c.budgets })}
                  </span>
                </div>
                <span
                  aria-hidden
                  className="bg-surface-sunken block h-1.5 overflow-hidden rounded-full"
                >
                  <span
                    className="bg-warning block h-full rounded-full"
                    style={{ width: `${String(Math.max(6, (c.risks / top) * 100))}%` }}
                  />
                </span>
              </li>
            ))}
          </ol>
        )}
      </CardContent>
    </Card>
  );
}
