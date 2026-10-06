import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { formatNumber } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import {
  barSeries,
  baseOption,
  Chart,
  ChartFrame,
  readChartPalette,
  seriesColour,
} from '@/shared/ui/chart';

type SignalsPanelProps = {
  /** Raw event name to count, over the same window as the funnel. */
  signals: Record<string, number>;
  /** The leads that arrived in the same window, for the comparison a reader will make anyway. */
  leads: number;
  windowDays: number;
  note: string;
};

/**
 * Counted taps and forms begun — beside the funnel, and never inside it.
 *
 * This panel is a deliberate piece of restraint. A WhatsApp tap is not a conversation, a
 * phone tap is not a call, and a form begun is not a form submitted; the counts are always
 * larger than the leads they produced, often tenfold. An agency that adds them reports a
 * cost per lead a fraction of the real one, wins next month's budget on it, and cannot
 * explain afterwards why the client's sales did not move.
 *
 * So the two numbers are **shown together and no ratio is drawn between them.** The gap is
 * the information. A percentage would be a claim, and it would be a claim with no
 * denominator anybody can defend: the same person taps three times, taps and then phones
 * instead, or taps on a device the form never loaded on.
 *
 * Its own chart with its own axis, for the same reason. One shared axis would invite the
 * eye to read the two as parts of one quantity, which is exactly the mistake.
 */
export function SignalsPanel({ signals, leads, windowDays, note }: SignalsPanelProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';

  const rows = useMemo(
    () =>
      Object.entries(signals)
        .map(([event, count]) => ({ event, count }))
        .sort((a, b) => b.count - a.count),
    [signals],
  );

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);
    const ordered = [...rows].reverse();

    return {
      ...base,
      grid: { top: 8, bottom: 8, left: 8, right: 8, containLabel: true },
      tooltip: {
        ...(base.tooltip as object),
        trigger: 'item',
        formatter: (params: unknown) => {
          const point = params as { dataIndex: number };
          const row = ordered[point.dataIndex];

          return row === undefined
            ? ''
            : `${row.event}<br/><strong>${formatNumber(row.count, locale)}</strong>`;
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
        data: ordered.map((row) => row.event),
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: palette.axisLabel, fontSize: 11, width: 170, overflow: 'truncate' },
      },
      series: [
        {
          ...barSeries(palette, {
            name: t('leads.signals.title'),
            data: ordered.map((row) => row.count),
            orientation: 'horizontal',
            isRtl,
            /*
             * Slot 5 rather than slot 1. These are not leads, and giving them the colour the
             * lead charts use would be the visual half of the claim this panel refuses to make.
             */
            colour: seriesColour(palette, 4),
          }),
          label: {
            show: true,
            position: isRtl ? 'left' : 'right',
            color: palette.textSecondary,
            fontSize: 11,
            formatter: (params: unknown) => {
              const point = params as { dataIndex: number };
              const row = ordered[point.dataIndex];

              return row === undefined ? '' : formatNumber(row.count, locale);
            },
          },
        },
      ],
    };
  }, [rows, isRtl, locale, t]);

  return (
    <ChartFrame
      title={t('leads.signals.title')}
      subtitle={t('leads.signals.subtitle', { days: windowDays })}
      caveats={[note]}
      isEmpty={rows.length === 0}
      emptyTitle={t('leads.signals.emptyTitle')}
      emptyBody={t('leads.signals.emptyBody')}
      table={{
        columns: [t('leads.signals.event'), t('leads.signals.count')],
        rows: rows.map((row) => ({
          key: row.event,
          label: row.event,
          cells: [formatNumber(row.count, locale)],
        })),
      }}
    >
      {/*
        The leads figure repeated here, as a sentence rather than as a second bar. A reader is
        going to compare the two columns whatever the interface does, so the interface says
        what the comparison is worth instead of pretending it will not happen.
      */}
      <Alert tone="info" className="mb-4">
        {t('leads.signals.comparison', {
          signals: formatNumber(
            rows.reduce((sum, row) => sum + row.count, 0),
            locale,
          ),
          leads: formatNumber(leads, locale),
        })}
      </Alert>

      <Chart
        option={option}
        height={Math.max(180, rows.length * 34 + 40)}
        ariaLabel={t('leads.signals.aria', { days: windowDays })}
      >
        <ul>
          {rows.map((row) => (
            <li key={row.event}>
              {row.event}: {formatNumber(row.count, locale)}
            </li>
          ))}
        </ul>
      </Chart>
    </ChartFrame>
  );
}
