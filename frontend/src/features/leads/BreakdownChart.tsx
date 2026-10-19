import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { formatMinor, formatNumber, formatRate } from '@/shared/lib/format';
import { Badge } from '@/shared/ui/badge';
import {
  barSeries,
  baseOption,
  Chart,
  ChartFrame,
  foldToOther,
  readChartPalette,
  seriesColour,
} from '@/shared/ui/chart';

/** A row of either breakdown, reduced to what a chart and a table both need. */
export type BreakdownRow = {
  key: string;
  label: string;
  total: number;
  qualified: number;
  won: number;
  winRate: number | null;
  valueMinor: number | null;
  currency: string | null;
  /** Shown as a warning: a hand-entered lead is real, and is not a campaign result. */
  isUnmeasured?: boolean;
  /** Shown as a warning: nothing identified where these leads came from. */
  isUnattributed?: boolean;
  /** How sure the attribution is, where there is one. */
  evidenceLabel?: string | null;
  confidence?: number | null;
};

type BreakdownChartProps = {
  title: string;
  subtitle: string;
  rows: BreakdownRow[];
  windowDays: number;
  /** Sentences about what this breakdown cannot say. */
  caveats?: string[];
};

/** A readable number of bars. Past it the tail is summed into one row that says its size. */
const MAX_BARS = 8;

/**
 * Leads by the route or the campaign they came through.
 *
 * Horizontal bars in one colour, because the categories have no inherent order and nothing
 * for a second colour to encode — shading each bar by its own value would spend the
 * identity channel restating the length a reader can already see.
 *
 * Two rows are never quietly dropped. The **unattributed** row — leads nothing identified —
 * stays in the chart, because hiding it makes every campaign look more productive than it
 * was and makes the totals disagree with the funnel above. And rows past the readable limit
 * are **summed into one** rather than cut, so a reader can see there is a tail and how big
 * it is.
 */
export function BreakdownChart({
  title,
  subtitle,
  rows,
  windowDays,
  caveats,
}: BreakdownChartProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const { rows: shown, folded } = useMemo(
    () =>
      foldToOther(
        [...rows].sort((a, b) => b.total - a.total),
        MAX_BARS,
        (tail) => ({
          key: 'other',
          label: t('chart.legend.other'),
          total: tail.reduce((sum, row) => sum + row.total, 0),
          qualified: tail.reduce((sum, row) => sum + row.qualified, 0),
          won: tail.reduce((sum, row) => sum + row.won, 0),
          /*
           * No win rate and no value on the folded row. Averaging rates across rows that each
           * had their own denominator produces a figure that is not any of theirs, and summing
           * amounts across rows whose currencies may differ produces one that is not money.
           */
          winRate: null,
          valueMinor: null,
          currency: null,
        }),
      ),
    [rows, t],
  );

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);
    const ordered = [...shown].reverse();

    return {
      ...base,
      grid: { top: 8, bottom: 8, left: 8, right: 8, containLabel: true },
      tooltip: {
        ...(base.tooltip as object),
        trigger: 'item',
        formatter: (params: unknown) => {
          const point = params as { dataIndex: number };
          const row = ordered[point.dataIndex];

          if (row === undefined) {
            return '';
          }

          const lines = [
            `<strong>${row.label}</strong>`,
            `${t('leads.table.leads')}: ${formatNumber(row.total, locale)}`,
            `${t('leads.table.won')}: ${formatNumber(row.won, locale)}`,
          ];

          if (row.winRate !== null) {
            lines.push(`${t('leads.table.winRate')}: ${formatRate(row.winRate, locale)}`);
          }

          return lines.join('<br/>');
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
        axisLabel: { color: palette.axisLabel, fontSize: 11, width: 170, overflow: 'truncate' },
      },
      series: [
        {
          ...barSeries(palette, {
            name: t('leads.table.leads'),
            data: ordered.map((row) => row.total),
            orientation: 'horizontal',
            isRtl,
            colour: seriesColour(palette, 0),
            /*
             * The two rows that need to look different are the ones a reader must not take at
             * face value: an unattributed row, and a tail that is a sum of others. Both wear
             * the de-emphasis grey — they are residues, not competitors.
             */
            colours: ordered.map((row) =>
              row.isUnattributed === true || row.key === 'other'
                ? palette.comparison
                : seriesColour(palette, 0),
            ),
          }),
          label: {
            show: true,
            position: isRtl ? 'left' : 'right',
            color: palette.textSecondary,
            fontSize: 11,
            formatter: (params: unknown) => {
              const point = params as { dataIndex: number };
              const row = ordered[point.dataIndex];

              return row === undefined ? '' : formatNumber(row.total, locale);
            },
          },
        },
      ],
    };
  }, [shown, isRtl, locale, t]);

  const footnotes = [
    ...(caveats ?? []),
    ...(folded > 0 ? [t('leads.breakdown.folded', { count: folded })] : []),
  ];

  return (
    <ChartFrame
      title={title}
      subtitle={subtitle}
      caveats={footnotes}
      isEmpty={rows.length === 0}
      emptyTitle={t('leads.breakdown.emptyTitle')}
      emptyBody={t('leads.breakdown.emptyBody', { days: windowDays })}
      table={{
        columns: [
          title,
          t('leads.table.leads'),
          t('leads.table.qualified'),
          t('leads.table.won'),
          t('leads.table.winRate'),
          t('leads.table.wonValue'),
        ],
        rows: shown.map((row) => ({
          key: row.key,
          label: (
            <span className="flex flex-wrap items-center gap-1.5">
              {row.label}
              {row.isUnmeasured === true ? (
                <Badge tone="warning">{t('leads.badge.notMeasured')}</Badge>
              ) : null}
              {row.isUnattributed === true ? (
                <Badge tone="warning">{t('leads.badge.unattributed')}</Badge>
              ) : null}
              {row.evidenceLabel == null ? null : (
                <Badge tone="neutral">
                  {row.confidence == null
                    ? row.evidenceLabel
                    : `${row.evidenceLabel} · ${formatNumber(row.confidence, locale)}%`}
                </Badge>
              )}
            </span>
          ),
          cells: [
            formatNumber(row.total, locale),
            formatNumber(row.qualified, locale),
            formatNumber(row.won, locale),
            row.winRate === null ? '—' : formatRate(row.winRate, locale),
            // Money and its currency travel together; without one there is no figure to show.
            row.valueMinor === null || row.currency === null
              ? '—'
              : formatMinor(row.valueMinor, row.currency, locale),
          ],
        })),
      }}
    >
      <Chart
        option={option}
        height={Math.max(200, shown.length * 34 + 40)}
        ariaLabel={t('leads.breakdown.aria', { title, days: windowDays })}
      >
        <ul>
          {shown.map((row) => (
            <li key={row.key}>
              {row.label}: {formatNumber(row.total, locale)}
            </li>
          ))}
        </ul>
      </Chart>
    </ChartFrame>
  );
}
