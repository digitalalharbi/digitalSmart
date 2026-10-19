import { useTranslation } from 'react-i18next';
import { Chart } from '@/shared/ui/chart/Chart';
import { formatMinor } from '@/shared/lib/format';
import { fromMinor } from '@/shared/lib/money';
import type { DailyRevenuePoint } from './types';

/**
 * Net revenue by day, with refunds beside it.
 *
 * The refunds are a second series rather than being netted away invisibly. A month whose sales look flat
 * because returns rose is a different business problem from a month where nobody bought anything, and the
 * only way to tell them apart on a chart is to draw both.
 *
 * No chart is drawn for a single day: a one-point line is decoration, and this product's charts are
 * supposed to answer a question.
 */
export function RevenueChart({
  points,
  currency,
  locale,
}: {
  points: DailyRevenuePoint[];
  currency: string;
  locale: string;
}) {
  const { t } = useTranslation();

  if (points.length < 2) {
    return (
      <p className="text-content-secondary py-8 text-center text-sm">
        {t('commerce.trendTooShort')}
      </p>
    );
  }

  const dates = points.map((point) => point.date);
  const net = points.map((point) => fromMinor(point.net_revenue_minor, currency));
  const refunded = points.map((point) => fromMinor(point.refunded_minor, currency));
  const hasRefunds = refunded.some((value) => value > 0);

  return (
    <Chart
      height={260}
      ariaLabel={t('commerce.chart.label', {
        from: dates[0],
        until: dates[dates.length - 1],
        total: formatMinor(
          points.reduce((sum, point) => sum + point.net_revenue_minor, 0),
          currency,
          locale,
        ),
      })}
      option={{
        grid: { left: 8, right: 8, top: 24, bottom: 8, containLabel: true },
        tooltip: { trigger: 'axis' },
        legend: hasRefunds
          ? { bottom: 0, data: [t('commerce.chart.net'), t('commerce.chart.refunded')] }
          : undefined,
        xAxis: { type: 'category', data: dates, boundaryGap: false },
        yAxis: {
          type: 'value',
          axisLabel: {
            formatter: (value: number) =>
              new Intl.NumberFormat(locale, { notation: 'compact' }).format(value),
          },
        },
        series: [
          {
            name: t('commerce.chart.net'),
            type: 'line',
            smooth: false,
            showSymbol: points.length <= 31,
            data: net,
            areaStyle: { opacity: 0.08 },
          },
          ...(hasRefunds
            ? [
                {
                  name: t('commerce.chart.refunded'),
                  type: 'bar' as const,
                  data: refunded,
                },
              ]
            : []),
        ],
      }}
    >
      {/*
       * Spelled out for a screen reader, because a canvas says nothing. The highest and lowest days are
       * what somebody actually asks of a revenue chart.
       */}
      <p>
        {t('commerce.chart.description', {
          best: bestDay(points, locale, currency),
          worst: worstDay(points, locale, currency),
        })}
      </p>
    </Chart>
  );
}

function bestDay(points: DailyRevenuePoint[], locale: string, currency: string): string {
  const best = points.reduce((a, b) => (b.net_revenue_minor > a.net_revenue_minor ? b : a));

  return `${best.date} (${formatMinor(best.net_revenue_minor, currency, locale)})`;
}

function worstDay(points: DailyRevenuePoint[], locale: string, currency: string): string {
  const worst = points.reduce((a, b) => (b.net_revenue_minor < a.net_revenue_minor ? b : a));

  return `${worst.date} (${formatMinor(worst.net_revenue_minor, currency, locale)})`;
}
