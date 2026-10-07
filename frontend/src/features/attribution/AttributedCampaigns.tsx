import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { formatCompact, formatNumber } from '@/shared/lib/format';
import { Badge } from '@/shared/ui/badge';
import {
  barSeries,
  baseOption,
  Chart,
  ChartFrame,
  readChartPalette,
  seriesColour,
} from '@/shared/ui/chart';
import { formatMinor } from './money';
import type { AttributionAnswer } from './types';

type AttributedCampaignsProps = {
  answer: AttributionAnswer;
};

/**
 * Attributed revenue per campaign, with the spend that can honestly sit beside it.
 *
 * One colour for every bar, because the categories are campaigns and swapping two of them changes
 * nothing — there is no order for a ramp to encode. The exception is the rows that **cannot carry
 * spend**: those wear the de-emphasis grey, because a reader comparing bars needs to see at a glance
 * which of them have a ROAS and which have an empty cell with a reason.
 *
 * The table twin carries what the chart cannot: the spend, the ROAS, the evidence behind each row, and
 * the sentence explaining every blank. On this surface the table is the more useful of the two views
 * for most questions, which is why it is a peer rather than a fallback.
 */
export function AttributedCampaigns({ answer }: AttributedCampaignsProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const isRtl = i18n.dir() === 'rtl';
  const currency = answer.coverage.currency;

  const option = useMemo<EChartsCoreOption>(() => {
    const palette = readChartPalette();
    const base = baseOption({ locale, isRtl }, palette);
    const ordered = [...answer.rows].reverse();

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

          const lines = [`<strong>${row.label}</strong>`];

          if (row.revenue_minor !== null && row.currency !== null) {
            lines.push(
              `${t('attribution.table.revenue')}: ${formatMinor(row.revenue_minor, row.currency, locale)}`,
            );
          }

          lines.push(
            row.spend_minor !== null && row.currency !== null
              ? `${t('attribution.table.spend')}: ${formatMinor(row.spend_minor, row.currency, locale)}`
              : `${t('attribution.table.spend')}: ${row.link_label}`,
          );

          if (row.roas !== null) {
            lines.push(`${t('attribution.table.roas')}: ${formatNumber(row.roas, locale, 2)}`);
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
          formatter: (value: number) => formatCompact(value / 100, locale),
        },
      },
      yAxis: {
        type: 'category',
        position: isRtl ? 'right' : 'left',
        data: ordered.map((row) => row.label),
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: palette.axisLabel, fontSize: 11, width: 190, overflow: 'truncate' },
      },
      series: [
        {
          ...barSeries(palette, {
            name: t('attribution.table.revenue'),
            data: ordered.map((row) => row.revenue_minor),
            orientation: 'horizontal',
            isRtl,
            /*
             * Grey for a row whose revenue cannot be put beside spend. Not a value ramp — a
             * two-state distinction that decides whether the row has a ROAS at all.
             */
            colours: ordered.map((row) =>
              row.joins_spend ? seriesColour(palette, 0) : palette.comparison,
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

              return row === undefined || row.revenue_minor === null || row.currency === null
                ? ''
                : formatMinor(row.revenue_minor, row.currency, locale);
            },
          },
        },
      ],
    };
  }, [answer.rows, isRtl, locale, t]);

  return (
    <ChartFrame
      title={t('attribution.campaigns.title')}
      subtitle={t('attribution.campaigns.subtitle', { model: answer.model_label })}
      caveats={answer.caveats}
      isEmpty={answer.rows.length === 0}
      emptyTitle={t('attribution.campaigns.emptyTitle')}
      emptyBody={t('attribution.campaigns.emptyBody')}
      table={{
        columns: [
          t('attribution.table.campaign'),
          t('attribution.table.orders'),
          t('attribution.table.revenue'),
          t('attribution.table.spend'),
          t('attribution.table.roas'),
        ],
        rows: answer.rows.map((row) => ({
          key: `${row.link}:${row.label}`,
          label: (
            <span className="flex flex-col gap-0.5">
              <span className="flex flex-wrap items-center gap-1.5">
                {row.label}
                <Badge tone={row.joins_spend ? 'neutral' : 'warning'}>{row.link_label}</Badge>
                <Badge tone="neutral">
                  {row.evidence_label} · {formatNumber(row.confidence, locale)}%
                </Badge>
              </span>
              {/*
                Why this row's spend cell is empty, next to the cell rather than in a footnote.
                "No campaign of this name exists in the connected accounts" is a tagging problem
                somebody can fix today; a blank is a report somebody quietly distrusts.
              */}
              {row.link_explanation === null ? null : (
                <span className="text-content-tertiary text-xs">{row.link_explanation}</span>
              )}
            </span>
          ),
          cells: [
            formatNumber(row.conversions, locale),
            row.revenue_minor === null || row.currency === null
              ? '—'
              : formatMinor(row.revenue_minor, row.currency, locale),
            row.spend_minor === null || row.currency === null
              ? '—'
              : formatMinor(row.spend_minor, row.currency, locale),
            // Null wherever dividing would be meaningless — see docs/ATTRIBUTION.md.
            row.roas === null ? '—' : formatNumber(row.roas, locale, 2),
          ],
        })),
      }}
    >
      <Chart
        option={option}
        height={Math.max(200, answer.rows.length * 34 + 40)}
        ariaLabel={t('attribution.campaigns.aria', { model: answer.model_label })}
      >
        <ul>
          {answer.rows.map((row) => (
            <li key={`${row.link}:${row.label}`}>
              {row.label}:{' '}
              {row.revenue_minor === null || row.currency === null
                ? '—'
                : formatMinor(row.revenue_minor, row.currency, locale)}
              {row.roas === null ? '' : ` · ROAS ${formatNumber(row.roas, locale, 2)}`}
            </li>
          ))}
        </ul>
      </Chart>

      {currency === null ? null : (
        <p className="text-content-secondary mt-4 text-sm">
          {answer.unmatched_spend_minor === null || answer.spend_currency === null
            ? t('attribution.campaigns.noSpend')
            : t('attribution.campaigns.unmatchedSpend', {
                amount: formatMinor(answer.unmatched_spend_minor, answer.spend_currency, locale),
              })}
        </p>
      )}
    </ChartFrame>
  );
}
