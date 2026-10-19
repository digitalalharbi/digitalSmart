import type { TFunction } from 'i18next';
import {
  DEFAULT_OPERATOR,
  NO_THRESHOLD,
  type Operator,
  type Rule,
  type RuleAction,
  type RuleMetric,
} from './types';

const UNIT: Partial<Record<RuleMetric, 'money' | 'ratio' | 'percent' | 'days'>> = {
  cost_per_result: 'money',
  spend: 'money',
  cpc: 'money',
  cpm: 'money',
  roas: 'ratio',
  budget_used: 'percent',
  ctr: 'percent',
  cost_change: 'percent',
  campaign_age: 'days',
  ends_within: 'days',
};

/** A limit the way a person says it: 500 SAR, 3.0×, 90%, 2d. */
export function limitText(
  metric: RuleMetric,
  value: number | null,
  currency?: string | null,
): string {
  if (value === null) return '';

  switch (UNIT[metric]) {
    case 'money':
      return currency
        ? `${value.toLocaleString('en-US')} ${currency}`
        : value.toLocaleString('en-US');
    case 'ratio':
      return `${value.toFixed(1)}×`;
    case 'percent':
      return `${String(value)}%`;
    case 'days':
      return `${String(value)}d`;
    default:
      return value.toLocaleString('en-US');
  }
}

/** One condition as one short line: "Spend > 500 SAR", "ROAS < 3.0×", "Not delivering". */
export function conditionText(
  t: TFunction,
  metric: RuleMetric,
  operator: Operator | undefined,
  threshold: number | null,
  currency?: string | null,
): string {
  const name = t(`automation.short.${metric}`);

  if (NO_THRESHOLD.has(metric)) return name;

  const op = (operator ?? DEFAULT_OPERATOR[metric]) === 'above' ? '>' : '<';

  return `${name} ${op} ${limitText(metric, threshold, currency)}`;
}

/**
 * A condition in parts, so a page can keep the metric name in the reading direction and isolate only the comparison
 * (`> 500 SAR`, `< 3.0×`) as left-to-right — mixing them in one LTR run scrambles Arabic.
 */
export function conditionParts(
  t: TFunction,
  metric: RuleMetric,
  operator: Operator | undefined,
  threshold: number | null,
  currency?: string | null,
): { name: string; comparison: string | null } {
  const name = t(`automation.short.${metric}`);

  if (NO_THRESHOLD.has(metric)) return { name, comparison: null };

  return {
    name,
    comparison: `${(operator ?? DEFAULT_OPERATOR[metric]) === 'above' ? '>' : '<'} ${limitText(metric, threshold, currency)}`,
  };
}

/** Every condition of a rule, in parts, in order. */
export function ruleConditionParts(
  t: TFunction,
  rule: Rule,
): Array<{ name: string; comparison: string | null }> {
  return [
    conditionParts(t, rule.metric, rule.operator, rule.threshold),
    ...rule.conditions.map((c) => conditionParts(t, c.metric, c.operator, c.threshold)),
  ];
}

/** Every condition of a rule, in order. */
export function ruleConditions(t: TFunction, rule: Rule): string[] {
  return [
    conditionText(t, rule.metric, rule.operator, rule.threshold),
    ...rule.conditions.map((c) => conditionText(t, c.metric, c.operator, c.threshold)),
  ];
}

/** "Decrease budget 20%", "Pause", "Notify". */
export function actionText(
  t: TFunction,
  action: RuleAction | 'notify',
  pct: number | null,
): string {
  // A budget or bid action recorded without its percentage is named, not shown as "−0%".
  if (pct === null && action.startsWith('propose_b')) return t(`automation.actPlain.${action}`);

  return t(`automation.act.${action}`, { pct });
}

/** A moment as a person reads it in a log: "19 Oct 2026, 08:45" — Western digits in both languages, like the rest of the product. */
export function when(iso: string, locale: string): string {
  return new Date(iso).toLocaleString(locale === 'ar' ? 'ar-SA-u-nu-latn-ca-gregory' : 'en-GB', {
    dateStyle: 'medium',
    timeStyle: 'short',
  });
}
