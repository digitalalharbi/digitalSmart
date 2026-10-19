import { PacingHero } from './PacingHero';
import { useUiStore } from '@/shared/store/ui-store';
import { SavedViewsMenu } from '@/features/surface-views';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Button } from '@/shared/ui/button';
import { useTranslation } from 'react-i18next';
import { api } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import { formatMinor, formatPercent } from '@/shared/lib/format';

function formatCompactMinor(minor: number, currency: string, locale: string): string {
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(minor / 100);
}
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { ProgressBar, ProgressRing } from '@/shared/ui/viz';
import {
  DataTable,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/ui/table';

type Risk = { risk: string; label: string; audience: string[] };

type Row = {
  id: string;
  name: string;
  client: { id: string; name: string };
  project: { id: string; name: string } | null;
  currency: string;
  approved_minor: number;
  allocated_minor: number;
  unallocated_minor: number;
  spent_minor: number | null;
  remaining_minor: number | null;
  spend_unavailable_reason: string | null;
  pacing: {
    expected_minor?: number;
    projected_minor: number | null;
    status: string;
    status_label: string;
    variance_percent: number | null;
    utilisation: number | null;
  } | null;
  risks: Risk[];
};

type Payload = {
  data: Row[];
  meta: {
    totals: Array<{
      currency: string;
      approved_minor: number;
      allocated_minor: number;
      spent_minor: number;
      unreported: number;
      at_risk: number;
    }>;
    count: number;
    risks: Array<{ risk: string; label: string; audience: string[] }>;
  };
};

/**
 * Every approved budget in the agency, with pacing and the risks it carries.
 *
 * Finance's surface: nobody opens a project to find out which budgets are in danger. A spend figure the
 * server could not total is shown as exactly that, with the reason — never as a zero, and never as a number
 * that quietly includes a second currency.
 */
export function BudgetControlPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const [risk, setRisk] = useState<string | null>(null);
  /* The working context narrows this surface like every other; the topbar chip says so on every screen. */
  const { context } = useUiStore();
  const clientId = context.client?.id ?? null;
  const projectId = context.project?.id ?? null;

  const { data, isPending, isError } = useQuery({
    queryKey: queryKeys.budgetControl.list(risk, clientId, projectId),
    queryFn: async (): Promise<Payload> => {
      const { data: body } = await api.get<Payload>('/budgets/control', {
        params: {
          ...(risk === null ? {} : { risk }),
          ...(clientId === null ? {} : { client_id: clientId }),
          ...(projectId === null ? {} : { project_id: projectId }),
        },
      });

      return body;
    },
    staleTime: 30_000,
  });

  /*
   * Overview is the answer: plan, pace, forecast and the totals. Breakdown is the working — every budget with its risks.
   * Choosing a risk is asking a breakdown question, so it opens the breakdown by itself.
   */
  const [breakdown, setBreakdown] = useState(false);
  const showBreakdown = breakdown || risk !== null;

  const money = (minor: number | null, currency: string) =>
    minor === null ? '—' : formatMinor(minor, currency, locale);

  return (
    <div className="ds-page">
      <PageHeader
        title={t('budgetControl.title')}
        description={t('budgetControl.subtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <div
              className="bg-surface-sunken flex rounded-lg p-0.5"
              role="group"
              aria-label={t('budgetControl.mode.label')}
            >
              {(['overview', 'breakdown'] as const).map((key) => (
                <Button
                  key={key}
                  size="sm"
                  variant={(key === 'breakdown') === showBreakdown ? 'secondary' : 'ghost'}
                  aria-pressed={(key === 'breakdown') === showBreakdown}
                  onClick={() => {
                    setBreakdown(key === 'breakdown');

                    if (key === 'overview') {
                      setRisk(null);
                    }
                  }}
                >
                  {t(`budgetControl.mode.${key}`)}
                </Button>
              ))}
            </div>
            <SavedViewsMenu
              surface="budget_control"
              current={risk === null ? {} : { risk }}
              presets={['overspend', 'underpace', 'near_limit', 'unallocated'].map((key) => ({
                key,
                label: t(`budgetControl.preset.${key}`),
                filters: { risk: key },
              }))}
              onApply={(saved) => setRisk(saved['risk'] ?? null)}
            />
          </div>
        }
      />

      {isError ? <Alert tone="danger">{t('budgetControl.loadFailed')}</Alert> : null}

      {isPending ? (
        <Skeleton className="h-56 w-full" />
      ) : (
        <PacingHero rows={data?.data ?? []} locale={locale} />
      )}

      <section
        aria-label={t('budgetControl.totals')}
        className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
      >
        {(data?.meta.totals ?? []).map((total) => {
          const remaining = total.approved_minor - total.spent_minor;

          return (
            <div
              key={total.currency}
              className="bg-surface border-line-subtle rounded-2xl border p-4 shadow-xs"
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="ds-overline text-content-tertiary">
                    {t('budgetControl.remaining')}
                  </p>
                  <p
                    className={`ds-numeric text-2xl font-semibold ${remaining < 0 ? 'text-danger' : 'text-content'}`}
                  >
                    {formatMinor(remaining, total.currency, locale)}
                  </p>
                  <p className="ds-overline text-content-tertiary mt-1">{total.currency}</p>
                </div>
                <ProgressRing
                  value={total.spent_minor}
                  max={Math.max(total.approved_minor, 1)}
                  tone={total.spent_minor > total.approved_minor ? 'bad' : 'brand'}
                  size={64}
                  label={t('budgetControl.used', { currency: total.currency })}
                />
              </div>
              {/* Three figures, each its own small tile — the part of the budget, not a row of a list. */}
              <dl className="mt-4 grid grid-cols-3 gap-2">
                {(
                  [
                    ['approved', total.approved_minor],
                    ['allocated', total.allocated_minor],
                    ['spent', total.spent_minor],
                  ] as const
                ).map(([key, minor]) => (
                  <div key={key} className="bg-surface-sunken min-w-0 rounded-xl p-2.5">
                    <dt className="text-content-tertiary truncate text-xs">
                      {t(`budgetControl.${key}`)}
                    </dt>
                    <dd className="ds-numeric text-content truncate text-sm font-semibold">
                      {formatCompactMinor(minor, total.currency, locale)}
                      {key === 'spent' && total.unreported > 0 ? (
                        <span className="text-content-tertiary"> *</span>
                      ) : null}
                    </dd>
                  </div>
                ))}
              </dl>
              {total.unreported > 0 ? (
                <p className="text-content-tertiary mt-2 text-xs">
                  {t('budgetControl.unreported', { count: total.unreported })}
                </p>
              ) : null}
            </div>
          );
        })}
      </section>

      {showBreakdown ? null : (
        <section aria-label={t('budgetControl.atRisk')} className="flex flex-col gap-2">
          <h2 className="text-content text-lg font-semibold">{t('budgetControl.atRisk')}</h2>
          {isPending ? (
            <Skeleton className="h-32 w-full" />
          ) : (data?.data ?? []).filter((row) => row.risks.length > 0).length === 0 ? (
            <p className="text-content-tertiary text-sm">{t('budgetControl.noneAtRisk')}</p>
          ) : (
            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {(data?.data ?? [])
                .filter((row) => row.risks.length > 0)
                .slice(0, 6)
                .map((row) => {
                  const spent = row.spent_minor;
                  const over = spent !== null && spent > row.approved_minor;

                  return (
                    <li
                      key={row.id}
                      className="bg-surface flex flex-col gap-3 rounded-3xl p-4 shadow-xs"
                    >
                      <span className="flex min-w-0 flex-col">
                        <span className="text-content truncate text-sm font-semibold" dir="auto">
                          {row.name}
                        </span>
                        <span className="text-content-tertiary truncate text-xs" dir="auto">
                          {[row.client.name, row.project?.name].filter(Boolean).join(' · ')}
                        </span>
                      </span>
                      {spent === null ? null : (
                        <div className="flex flex-col gap-1.5">
                          {/* The bar is the budget; the tick is where an even pace would be today. */}
                          <ProgressBar
                            value={spent}
                            max={Math.max(row.approved_minor, 1)}
                            marker={row.pacing?.expected_minor}
                            tone={
                              over
                                ? 'bad'
                                : row.risks.some((r) => r.risk === 'overspend')
                                  ? 'bad'
                                  : 'watch'
                            }
                            label={t('budgetControl.spent')}
                            height={10}
                          />
                          <span
                            className="ds-numeric text-content-secondary flex justify-between text-xs"
                            dir="ltr"
                          >
                            <span>{formatCompactMinor(spent, row.currency, locale)}</span>
                            <span>
                              {formatCompactMinor(row.approved_minor, row.currency, locale)}
                            </span>
                          </span>
                        </div>
                      )}
                      <span className="flex flex-wrap gap-1">
                        {row.risks.map((r) => (
                          <Badge key={r.risk} tone={r.risk === 'overspend' ? 'danger' : 'warning'}>
                            {r.label}
                          </Badge>
                        ))}
                      </span>
                    </li>
                  );
                })}
            </ul>
          )}
        </section>
      )}

      {showBreakdown ? (
        <>
          <ChoiceGroup label={t('budgetControl.filter')}>
            <div className="flex flex-wrap gap-2">
              <Choice isSelected={risk === null} onSelect={() => setRisk(null)}>
                {t('budgetControl.all')}
              </Choice>
              {(data?.meta.risks ?? []).map((r) => (
                <Choice key={r.risk} isSelected={risk === r.risk} onSelect={() => setRisk(r.risk)}>
                  {r.label}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>

          {isPending ? (
            <Skeleton className="h-56 w-full" />
          ) : data === undefined || data.data.length === 0 ? (
            <EmptyState
              title={t('budgetControl.empty')}
              description={t('budgetControl.emptyBody')}
            />
          ) : (
            <DataTable label={t('budgetControl.title')} minWidth="64rem">
              <TableHead>
                <TableRow>
                  <TableHeader>{t('budgetControl.col.budget')}</TableHeader>
                  <TableHeader align="end">{t('budgetControl.approved')}</TableHeader>
                  <TableHeader align="end">{t('budgetControl.allocated')}</TableHeader>
                  <TableHeader align="end">{t('budgetControl.spent')}</TableHeader>
                  <TableHeader align="end">{t('budgetControl.remaining')}</TableHeader>
                  <TableHeader>{t('budgetControl.col.pacing')}</TableHeader>
                  <TableHeader>{t('budgetControl.col.risks')}</TableHeader>
                </TableRow>
              </TableHead>
              <TableBody>
                {data.data.map((row) => (
                  <TableRow key={row.id}>
                    <TableCell>
                      <span className="flex flex-col">
                        <span className="text-content font-medium">{row.name}</span>
                        <span className="text-content-tertiary text-xs">
                          {[row.client.name, row.project?.name].filter(Boolean).join(' · ')}
                        </span>
                      </span>
                    </TableCell>
                    <TableCell isNumeric align="end" label={t('budgetControl.approved')}>
                      {money(row.approved_minor, row.currency)}
                    </TableCell>
                    <TableCell isNumeric align="end" label={t('budgetControl.allocated')}>
                      {money(row.allocated_minor, row.currency)}
                    </TableCell>
                    <TableCell isNumeric align="end" label={t('budgetControl.spent')}>
                      <span title={row.spend_unavailable_reason ?? undefined}>
                        {money(row.spent_minor, row.currency)}
                      </span>
                    </TableCell>
                    <TableCell isNumeric align="end" label={t('budgetControl.remaining')}>
                      {money(row.remaining_minor, row.currency)}
                    </TableCell>
                    <TableCell label={t('budgetControl.col.pacing')}>
                      {row.pacing === null ? (
                        <span className="text-content-tertiary text-xs">
                          {row.spend_unavailable_reason}
                        </span>
                      ) : (
                        <span className="flex flex-col text-sm">
                          {row.pacing.status_label}
                          {row.pacing.variance_percent === null ? null : (
                            <span className="text-content-tertiary text-xs">
                              {formatPercent(row.pacing.variance_percent, locale, 0)}
                            </span>
                          )}
                        </span>
                      )}
                    </TableCell>
                    <TableCell label={t('budgetControl.col.risks')}>
                      <span className="flex flex-wrap gap-1">
                        {row.risks.length === 0 ? (
                          <Badge tone="success">{t('budgetControl.noRisk')}</Badge>
                        ) : (
                          row.risks.map((r) => (
                            <Badge
                              key={r.risk}
                              tone={r.risk === 'overspend' ? 'danger' : 'warning'}
                            >
                              {r.label}
                            </Badge>
                          ))
                        )}
                      </span>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </DataTable>
          )}
        </>
      ) : null}
    </div>
  );
}
