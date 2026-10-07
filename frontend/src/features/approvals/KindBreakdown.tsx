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
  readChartPalette,
  seriesColour,
} from '@/shared/ui/chart';
import type { ApprovalKindInfo, ApprovalLoad } from './types';

type KindBreakdownProps = {
  load: ApprovalLoad;
  kinds: ApprovalKindInfo[];
};

/**
 * What kind of change is waiting for a decision.
 *
 * **One series, one colour, no legend.** The kinds are named on the axis, so colour would be encoding something
 * already said in words — and a second encoding of the same fact is ink that is not data. The chart's title
 * names what is plotted, which is what a single-series chart needs instead of a legend.
 *
 * Which kinds move a client's money is marked in the **table**, as a badge, rather than by a second hue. That
 * keeps colour doing one job: a bar shaded to mean "spend" while its neighbour's shade meant "kind" is how a
 * reader learns to distrust the palette.
 */
export function KindBreakdown({ load, kinds }: KindBreakdownProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const rows = useMemo(
    () =>
      kinds
        .map((kind) => ({ ...kind, pending: load.pending_by_kind[kind.kind] ?? 0 }))
        /*
         * Kinds with nothing waiting are left out. A bar of zero beside four real ones reads as a measurement
         * of nothing, and the table carries the full vocabulary for anybody who wants it.
         */
        .filter((kind) => kind.pending > 0)
        .sort((a, b) => b.pending - a.pending),
    [kinds, load.pending_by_kind],
  );

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);
    const drawn = [...rows].reverse();

    return {
      ...base,
      grid: { top: 8, bottom: 8, left: 8, right: 8, containLabel: true },
      tooltip: {
        ...(base.tooltip as object),
        trigger: 'item',
        formatter: (params: unknown) => {
          const point = params as { dataIndex: number };
          const row = drawn[point.dataIndex];

          return row === undefined
            ? ''
            : `${row.label}<br/><strong>${formatNumber(row.pending, locale)}</strong>`;
        },
      },
      xAxis: { type: 'value', inverse: isRtl, show: false },
      yAxis: {
        type: 'category',
        position: isRtl ? 'right' : 'left',
        data: drawn.map((row) => row.label),
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: palette.textSecondary, fontSize: 12 },
      },
      series: [
        {
          ...barSeries(palette, {
            name: t('approvals.kinds.title'),
            data: drawn.map((row) => row.pending),
            orientation: 'horizontal',
            isRtl,
            // One hue. The kinds are named on the axis; colour has nothing left to say.
            colour: seriesColour(palette, 0),
          }),
          label: {
            show: true,
            position: isRtl ? 'left' : 'right',
            color: palette.textSecondary,
            fontSize: 11,
            formatter: (params: unknown) =>
              formatNumber((params as { value: number }).value, locale),
          },
        },
      ],
    };
  }, [rows, isRtl, locale, t]);

  return (
    <ChartFrame
      title={t('approvals.kinds.title')}
      subtitle={t('approvals.kinds.subtitle')}
      caveats={[t('approvals.kinds.caveat')]}
      isEmpty={rows.length === 0}
      emptyTitle={t('approvals.kinds.emptyTitle')}
      emptyBody={t('approvals.kinds.emptyBody')}
      table={{
        columns: [t('approvals.kinds.kind'), t('approvals.kinds.waiting')],
        // Every kind, including the ones with nothing waiting: the zero is information in a table.
        rows: kinds.map((kind) => ({
          key: kind.kind,
          label: (
            <span className="flex flex-wrap items-center gap-1.5">
              {kind.label}
              {kind.touches_live_spend ? (
                <Badge tone="warning">{t('approvals.kinds.spend')}</Badge>
              ) : null}
            </span>
          ),
          cells: [formatNumber(load.pending_by_kind[kind.kind] ?? 0, locale)],
        })),
      }}
    >
      <Chart
        option={option}
        height={Math.max(180, rows.length * 40 + 40)}
        ariaLabel={t('approvals.kinds.aria')}
      >
        <ul>
          {rows.map((row) => (
            <li key={row.kind}>
              {row.label}: {formatNumber(row.pending, locale)}
            </li>
          ))}
        </ul>
      </Chart>
    </ChartFrame>
  );
}
