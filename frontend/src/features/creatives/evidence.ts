import { localeTag } from '@/shared/lib/format';
import type { CreativeRow } from './types';

/** A word-sized reading of the evidence, e.g. "CTR −32%". Only the signals a person can act on. */
export function evidenceChips(
  row: CreativeRow,
  locale: string,
  t: (key: string) => string,
): string[] {
  const percent = new Intl.NumberFormat(localeTag(locale), {
    style: 'percent',
    maximumFractionDigits: 0,
    signDisplay: 'exceptZero',
  });
  const labels: Record<string, string> = {
    ctr_change: t('creatives.evidence.ctr'),
    frequency_change: t('creatives.evidence.frequency'),
    cost_change: t('creatives.evidence.cost'),
    conversion_rate_change: t('creatives.evidence.conversionRate'),
  };

  return row.evidence.flatMap(({ signal, value }) =>
    typeof value === 'number' && labels[signal] !== undefined
      ? [`${labels[signal]} ${percent.format(value)}`]
      : [],
  );
}
