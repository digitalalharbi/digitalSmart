import { lazy, Suspense, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { TriangleAlert } from 'lucide-react';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Card } from '@/shared/ui/card';
import { Skeleton } from '@/shared/ui/skeleton';
import { SourceBadge } from '@/shared/ui/source-badge';
import { formatCurrency, formatMinor, formatPercent } from '@/shared/lib/format';
import { cn } from '@/shared/lib/cn';
import { ChartFrame } from '@/shared/ui/chart';
import { pacingOption, pacingSeries } from './pacingChart';
import type { AllocationPacingPayload, PacingPayload, PacingStatusValue } from './types';

const Chart = lazy(async () => {
  const module = await import('@/shared/ui/chart/Chart');

  return { default: module.Chart };
});

const STATUS_TONE: Record<
  PacingStatusValue,
  'neutral' | 'success' | 'warning' | 'danger' | 'info'
> = {
  not_started: 'neutral',
  on_track: 'success',
  underspending: 'warning',
  overspending: 'danger',
  exhausted: 'danger',
  ended: 'neutral',
  // Not a reassuring grey: "we cannot tell" is a thing to look at.
  unknown: 'warning',
};

/**
 * Is this budget going to land where it was meant to?
 *
 * The question the whole panel answers, in three registers: the status word, the gap
 * between two curves, and a row per allocation for finding *which* part is wrong. An
 * allocation table without the chart leaves a reader doing arithmetic; a chart without
 * the table leaves them knowing something is off and not what.
 *
 * Every figure that cannot be stated is stated as such — a missing spend total shows its
 * reason, not a zero — because an understated spend is the one direction that overcharges
 * a client quietly.
 */
export function PacingPanel({ pacing }: { pacing: PacingPayload }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en';
  const isRtl = i18n.dir() === 'rtl';
  const currency = pacing.budget.currency;

  const option = useMemo(() => {
    if (pacing.pacing === null) {
      return null;
    }

    return pacingOption(
      pacing.daily,
      pacing.pacing,
      pacing.budget.period_start,
      pacing.budget.period_end,
      {
        locale,
        isRtl,
        currency,
        labels: {
          actual: t('budgets.pacing.chart.actual'),
          evenPace: t('budgets.pacing.chart.evenPace'),
          budget: t('budgets.pacing.chart.budget'),
        },
      },
    );
  }, [pacing, locale, isRtl, currency, t]);

  /* The same arithmetic the chart is drawn from, for the table beside it. */
  const curves = useMemo(() => {
    if (pacing.pacing === null) {
      return null;
    }

    return pacingSeries(
      pacing.daily,
      pacing.pacing,
      pacing.budget.period_start,
      pacing.budget.period_end,
    );
  }, [pacing]);

  return (
    <div className="flex flex-col gap-4">
      {pacing.unavailable_reason === null ? null : (
        <Alert tone="warning" className="flex items-start gap-2">
          <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          <span>{pacing.unavailable_reason}</span>
        </Alert>
      )}

      {/*
       * A named landmark rather than a card among cards: "how is this pacing" becomes
       * navigable for a screen reader, and no extra element is added — `Card` already
       * spreads the attributes onto its own div.
       */}
      <Card
        role="region"
        aria-label={t('budgets.pacing.region')}
        className="flex flex-col gap-4 p-4"
      >
        <header className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex flex-wrap items-center gap-2">
            {pacing.pacing === null ? (
              <Badge tone="warning">{t('budgets.pacing.notPaced')}</Badge>
            ) : (
              <Badge tone={STATUS_TONE[pacing.pacing.status]}>{pacing.pacing.status_label}</Badge>
            )}
            <SourceBadge source={pacing.spend.source} />
          </div>
          {pacing.pacing === null ? null : (
            <p className="text-content-tertiary text-xs">
              {t('budgets.pacing.dayOf', {
                elapsed: pacing.pacing.elapsed_days,
                total: pacing.pacing.total_days,
              })}
            </p>
          )}
        </header>

        <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Figure
            label={t('budgets.pacing.figures.budget')}
            value={formatMinor(pacing.budget.amount_minor, currency, locale)}
          />
          <Figure
            label={t('budgets.pacing.figures.spent')}
            value={
              pacing.spend.minor_units === null
                ? '—'
                : formatMinor(pacing.spend.minor_units, currency, locale)
            }
            /*
             * Only when the panel is not already saying it. The same sentence in a banner
             * and under the figure reads as two different problems.
             */
            note={
              pacing.spend.unavailable_reason === pacing.unavailable_reason
                ? null
                : pacing.spend.unavailable_reason
            }
          />
          <Figure
            label={t('budgets.pacing.figures.expected')}
            value={
              pacing.pacing === null
                ? '—'
                : formatMinor(pacing.pacing.expected_minor, currency, locale)
            }
            note={
              pacing.pacing === null
                ? null
                : t('budgets.pacing.evenPaceNote', {
                    tolerance: formatPercent(pacing.pacing.tolerance, locale, 0),
                  })
            }
          />
          <Figure
            label={t('budgets.pacing.figures.projected')}
            value={
              pacing.pacing?.projected_minor == null
                ? '—'
                : formatMinor(pacing.pacing.projected_minor, currency, locale)
            }
            note={
              pacing.pacing?.projected_minor == null
                ? t('budgets.pacing.noProjection')
                : pacing.pacing.risks_overspend
                  ? t('budgets.pacing.risksOverspend')
                  : pacing.pacing.risks_under_delivery
                    ? t('budgets.pacing.risksUnderDelivery')
                    : null
            }
            tone={
              pacing.pacing?.risks_overspend === true
                ? 'danger'
                : pacing.pacing?.risks_under_delivery === true
                  ? 'warning'
                  : 'neutral'
            }
          />
        </dl>

        {option === null || curves === null ? null : (
          /*
           * In the shared frame, like every other chart in the product. Not cosmetic: the frame
           * carries the table twin and the pattern channel for readers colour cannot reach, and a
           * chart outside it has no way to turn that channel on. The two curves in the table come
           * from `pacingSeries`, the same function the chart is drawn from, so the figures beside
           * the chart cannot disagree with the chart.
           */
          <ChartFrame
            title={t('budgets.pacing.chart.title')}
            subtitle={t('budgets.pacing.chart.hint')}
            /*
             * No source badge here, and that is this panel's own rule applied again: its header
             * already states where the spend came from, for the four figures as well as the chart,
             * and the same claim twice reads as two claims. Every other chart in the product carries
             * its own badge because it is the only thing stating its source.
             */
            caveats={[t('budgets.pacing.chart.caveat')]}
            table={{
              columns: [
                t('budgets.pacing.chart.day'),
                t('budgets.pacing.chart.actual'),
                t('budgets.pacing.chart.evenPace'),
              ],
              rows: curves.dates.map((date, index) => ({
                key: date,
                label: date,
                cells: [
                  /*
                   * A day past the last report is a dash, never a repeated total. The chart says
                   * the same thing by ending the line, and the table has to say it too.
                   */
                  curves.actual[index] == null
                    ? '—'
                    : formatCurrency(curves.actual[index], currency, locale),
                  formatCurrency(curves.evenPace[index] ?? 0, currency, locale),
                ],
              })),
            }}
          >
            <Suspense fallback={<Skeleton className="h-64" />}>
              <Chart
                option={option}
                height={260}
                ariaLabel={t('budgets.pacing.chart.description', {
                  budget: formatMinor(pacing.budget.amount_minor, currency, locale),
                  spent:
                    pacing.spend.minor_units === null
                      ? t('budgets.pacing.chart.spendUnknown')
                      : formatMinor(pacing.spend.minor_units, currency, locale),
                })}
              />
            </Suspense>
          </ChartFrame>
        )}
      </Card>

      {pacing.allocations.length === 0 ? null : (
        <Card
          role="group"
          aria-label={t('budgets.pacing.allocationsCaption')}
          tabIndex={0}
          className="overflow-x-auto p-0"
        >
          <table className="w-full text-sm">
            <caption className="text-content-tertiary px-4 pt-4 pb-2 text-start text-xs">
              {t('budgets.pacing.allocationsCaption')}
            </caption>
            <thead>
              <tr className="border-line-subtle border-b">
                <Th>{t('budgets.pacing.table.allocation')}</Th>
                <Th>{t('budgets.pacing.table.level')}</Th>
                <Th align="end">{t('budgets.pacing.table.planned')}</Th>
                <Th align="end">{t('budgets.pacing.table.spent')}</Th>
                <Th align="end">{t('budgets.pacing.table.variance')}</Th>
                <Th>{t('budgets.pacing.table.status')}</Th>
              </tr>
            </thead>
            <tbody>
              {pacing.allocations.map((row) => (
                <AllocationRow key={row.allocation_id} row={row} locale={locale} />
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </div>
  );
}

function AllocationRow({ row, locale }: { row: AllocationPacingPayload; locale: string }) {
  const { t } = useTranslation();
  const spend = row.spend.minor_units;

  return (
    <tr className="border-line-subtle border-b last:border-0">
      <td className="px-4 py-2">
        <div className="flex flex-col">
          <span className="text-content">{row.label}</span>
          {row.parent_label === null ? null : (
            <span className="text-content-tertiary text-xs">{row.parent_label}</span>
          )}
        </div>
      </td>
      <td className="text-content-secondary px-4 py-2">{row.level_label}</td>
      <td className="text-content px-4 py-2 text-end tabular-nums">
        {formatMinor(row.amount_minor, row.currency, locale)}
      </td>
      <td className="px-4 py-2 text-end tabular-nums">
        {spend === null ? (
          <span className="text-content-tertiary">—</span>
        ) : (
          formatMinor(spend, row.currency, locale)
        )}
      </td>
      <td className="px-4 py-2 text-end tabular-nums">
        {row.pacing === null ? (
          <span className="text-content-tertiary">—</span>
        ) : (
          <span className={row.pacing.variance_minor > 0 ? 'text-danger' : 'text-content'}>
            {formatMinor(row.pacing.variance_minor, row.currency, locale)}
          </span>
        )}
      </td>
      <td className="px-4 py-2">
        {row.pacing === null ? (
          <span className="text-content-tertiary text-xs">
            {row.unavailable_reason ?? row.spend.unavailable_reason ?? t('budgets.pacing.noData')}
          </span>
        ) : (
          <Badge tone={STATUS_TONE[row.pacing.status]}>{row.pacing.status_label}</Badge>
        )}
      </td>
    </tr>
  );
}

function Th({ children, align = 'start' }: { children: string; align?: 'start' | 'end' }) {
  return (
    <th
      scope="col"
      className={cn(
        'text-content-tertiary px-4 py-2 text-xs font-medium',
        align === 'end' ? 'text-end' : 'text-start',
      )}
    >
      {children}
    </th>
  );
}

function Figure({
  label,
  value,
  note,
  tone = 'neutral',
}: {
  label: string;
  value: string;
  note?: string | null;
  tone?: 'neutral' | 'warning' | 'danger';
}) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-content-tertiary text-xs font-medium">{label}</dt>
      <dd
        className={cn(
          'text-base font-semibold tabular-nums',
          value === '—' ? 'text-content-tertiary' : 'text-content',
        )}
      >
        {value}
      </dd>
      {note === null || note === undefined ? null : (
        <dd
          className={cn(
            'text-xs',
            tone === 'danger'
              ? 'text-danger'
              : tone === 'warning'
                ? 'text-warning'
                : 'text-content-tertiary',
          )}
        >
          {note}
        </dd>
      )}
    </div>
  );
}
