import type { MetricSourceValue, MetricValue } from '@/shared/metrics';
import type { StoreRevenueSummary } from './types';

/**
 * Money for an inline sentence.
 *
 * Divides by a hundred exactly once, here, so nothing rounds twice on its way to a reader.
 */
export function formatMinor(minor: number, currency: string, locale: string): string {
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
  }).format(minor / 100);
}

/** An inclusive window of the last `days` days, as the API takes it. */
export function windowFor(days: number): { from: string; until: string } {
  const until = new Date();
  const from = new Date(until);

  // Inclusive both ends, so "7 days" covers seven days rather than eight.
  from.setDate(from.getDate() - (days - 1));

  return { from: isoDate(from), until: isoDate(until) };
}

function isoDate(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${date.getFullYear()}-${month}-${day}`;
}

/**
 * One summary figure as a `MetricValue`, so the shared card formats it.
 *
 * Built rather than hand-rendered because `MetricCard` already carries the two rules that matter: a null
 * value renders as absent rather than as zero, and every figure shows where it came from. Reimplementing
 * either here would be reimplementing them differently.
 */
export function summaryMetric(options: {
  metric: string;
  label: string;
  value: number | null;
  format: MetricValue['format'];
  source: MetricSourceValue;
  sourceLabel: string;
  currency?: string | null;
  isMinorUnits?: boolean;
  unavailableReason?: string | null;
}): MetricValue {
  return {
    metric: options.metric,
    label: options.label,
    value: options.value,
    format: options.format,
    precision: options.format === 'currency' ? 2 : 0,
    is_minor_units: options.isMinorUnits ?? false,
    currency: options.currency ?? null,
    source: options.source,
    source_label: options.sourceLabel,
    requires_label: true,
    unavailable_reason: options.unavailableReason ?? null,
    comparison_value: null,
    change_ratio: null,
    change_is_good: null,
    lower_is_better: false,
  };
}

/**
 * The four figures a store summary leads with.
 *
 * Average order value is deliberately allowed to be null, with the reason attached: a denominator of zero
 * has no average, and a zero here would be compared with last month's by the first person who saw it.
 */
export function summaryMetrics(
  summary: StoreRevenueSummary,
  labels: { netRevenue: string; orders: string; aov: string; customers: string; noOrders: string },
): MetricValue[] {
  const common = {
    source: summary.source,
    sourceLabel: summary.source_label,
  };

  return [
    summaryMetric({
      ...common,
      metric: 'net_revenue',
      label: labels.netRevenue,
      value: summary.net_revenue_minor,
      format: 'currency',
      currency: summary.currency,
      isMinorUnits: true,
    }),
    summaryMetric({
      ...common,
      metric: 'orders',
      label: labels.orders,
      value: summary.orders,
      format: 'count',
    }),
    summaryMetric({
      ...common,
      metric: 'aov',
      label: labels.aov,
      value: summary.average_order_value_minor,
      format: 'currency',
      currency: summary.currency,
      isMinorUnits: true,
      unavailableReason: summary.average_order_value_minor === null ? labels.noOrders : null,
    }),
    summaryMetric({
      ...common,
      metric: 'customers',
      label: labels.customers,
      value: summary.customers,
      format: 'count',
    }),
  ];
}
