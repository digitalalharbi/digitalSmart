import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { formatNumber } from '@/shared/lib/format';
import { Badge } from '@/shared/ui/badge';
import {
  barSeries,
  baseOption,
  Chart,
  ChartFrame,
  legend,
  readChartPalette,
  seriesColour,
} from '@/shared/ui/chart';
import type { EventDefinition } from './types';

type ObservedEventsChartProps = {
  definitions: EventDefinition[];
  windowDays: number;
};

/**
 * What has actually been seen, against what the client said success is.
 *
 * The chart that answers the only question the Tracking Center exists for: is any of this
 * working? A conversion defined and never observed is the most actionable row in the product
 * — somebody has declared what a sale looks like and nothing has ever matched it — and in a
 * table of twenty definitions it is invisible.
 *
 * Three states, and all three are different facts:
 *
 *  - **A count.** The event has been seen that many times in the window.
 *  - **Zero.** Asked for, and never seen. Drawn as a visible zero-length bar with its label,
 *    because this is the finding, not the absence of one.
 *  - **Null.** No window was asked for, so nothing was counted. Drawn as a **gap** rather than
 *    a zero: "we did not look" and "we looked and found nothing" are opposite statements, and
 *    only one of them means tracking is broken.
 *
 * Conversions and intent signals get their own colour and their own legend entry, and nothing
 * is stacked or totalled. A WhatsApp tap and a completed enquiry are both in this chart because
 * both were defined, and the one thing the chart must not do is let them add up.
 */
export function ObservedEventsChart({ definitions, windowDays }: ObservedEventsChartProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const rows = useMemo(
    () =>
      [...definitions]
        .map((definition) => ({
          id: definition.id,
          label: definition.name,
          count: definition.observed_count,
          isSignal: definition.is_intent_signal_only,
          isPrimary: definition.is_primary,
          lastSeen: definition.last_seen_date,
        }))
        /*
         * Never-seen definitions first. They are the findings, and a chart that buries them
         * under the busiest events is a chart nobody acts on.
         */
        .sort((a, b) => {
          const rank = (value: number | null): number => (value === 0 ? 0 : value === null ? 1 : 2);

          return rank(a.count) - rank(b.count) || (b.count ?? 0) - (a.count ?? 0);
        }),
    [definitions],
  );

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);
    const ordered = [...rows].reverse();

    const seriesFor = (isSignal: boolean, name: string, slot: number) => ({
      ...barSeries(palette, {
        name,
        /*
         * One series per kind, with the other kind's rows left null so each bar belongs to
         * exactly one legend entry. Null is a gap here as everywhere — nothing is drawn.
         */
        data: ordered.map((row) => (row.isSignal === isSignal ? row.count : null)),
        orientation: 'horizontal',
        isRtl,
        colour: seriesColour(palette, slot),
      }),
      label: {
        show: true,
        position: isRtl ? ('left' as const) : ('right' as const),
        color: palette.textSecondary,
        fontSize: 11,
        formatter: (params: unknown) => {
          const point = params as { dataIndex: number };
          const row = ordered[point.dataIndex];

          if (row === undefined || row.isSignal !== isSignal) {
            return '';
          }

          // The three states, said in words where a bar cannot say them.
          return row.count === null
            ? t('tracking.observed.notCounted')
            : row.count === 0
              ? t('tracking.observed.neverSeen')
              : formatNumber(row.count, locale);
        },
      },
    });

    return {
      ...base,
      grid: { top: 28, bottom: 8, left: 8, right: 8, containLabel: true },
      legend: legend({ locale, isRtl }, palette, 2),
      tooltip: {
        ...(base.tooltip as object),
        trigger: 'item',
        formatter: (params: unknown) => {
          const point = params as { dataIndex: number };
          const row = ordered[point.dataIndex];

          if (row === undefined) {
            return '';
          }

          const seen =
            row.count === null
              ? t('tracking.observed.notCounted')
              : row.count === 0
                ? t('tracking.observed.neverSeen')
                : formatNumber(row.count, locale);

          return [
            `<strong>${row.label}</strong>`,
            `${t('tracking.observed.seen')}: ${seen}`,
            row.lastSeen === null
              ? t('tracking.observed.neverSeen')
              : `${t('tracking.observed.lastSeen')}: ${row.lastSeen}`,
          ].join('<br/>');
        },
      },
      xAxis: {
        type: 'value',
        inverse: isRtl,
        splitLine: { lineStyle: { color: palette.grid, type: 'solid' } },
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: {
          color: palette.axisLabel,
          fontSize: 11,
          formatter: (value: number) => formatNumber(value, locale),
        },
      },
      yAxis: {
        type: 'category',
        position: isRtl ? 'right' : 'left',
        data: ordered.map((row) => row.label),
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: palette.axisLabel, fontSize: 11, width: 180, overflow: 'truncate' },
      },
      series: [
        seriesFor(false, t('tracking.observed.conversions'), 0),
        // Slot 5, deliberately far from the conversion colour: these must never read as the same thing.
        seriesFor(true, t('tracking.observed.signals'), 4),
      ],
    };
  }, [rows, isRtl, locale, t]);

  return (
    <ChartFrame
      title={t('tracking.observed.title')}
      subtitle={t('tracking.observed.subtitle', { days: windowDays })}
      caveats={[t('tracking.observed.caveat')]}
      isEmpty={rows.length === 0}
      emptyTitle={t('tracking.observed.emptyTitle')}
      emptyBody={t('tracking.observed.emptyBody')}
      table={{
        columns: [
          t('tracking.observed.event'),
          t('tracking.observed.seen'),
          t('tracking.observed.lastSeen'),
        ],
        rows: rows.map((row) => ({
          key: row.id,
          label: (
            <span className="flex flex-wrap items-center gap-1.5">
              {row.label}
              {row.isSignal ? (
                <Badge tone="warning">{t('tracking.observed.signalBadge')}</Badge>
              ) : null}
              {row.isPrimary ? (
                <Badge tone="accent">{t('tracking.observed.primaryBadge')}</Badge>
              ) : null}
            </span>
          ),
          cells: [
            row.count === null
              ? t('tracking.observed.notCounted')
              : row.count === 0
                ? t('tracking.observed.neverSeen')
                : formatNumber(row.count, locale),
            row.lastSeen ?? '—',
          ],
        })),
      }}
    >
      <Chart
        option={option}
        height={Math.max(220, rows.length * 34 + 60)}
        ariaLabel={t('tracking.observed.aria', { days: windowDays })}
      >
        <ul>
          {rows.map((row) => (
            <li key={row.id}>
              {row.label}:{' '}
              {row.count === null
                ? t('tracking.observed.notCounted')
                : row.count === 0
                  ? t('tracking.observed.neverSeen')
                  : formatNumber(row.count, locale)}
            </li>
          ))}
        </ul>
      </Chart>
    </ChartFrame>
  );
}
