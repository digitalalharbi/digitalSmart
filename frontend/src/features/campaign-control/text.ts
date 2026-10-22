import { formatMinor, formatNumber } from '@/shared/lib/format';
import type { CampaignRow } from './types';

/** A figure, or a dash. A null is "nobody reported", and it is never rendered as a zero. */
export function money(
  minor: number | null | undefined,
  currency: string | null,
  locale: string,
): string {
  return minor === null || minor === undefined || currency === null
    ? '—'
    : formatMinor(Math.round(minor), currency, locale);
}

/** The objective's own result: a count of what the campaign was built to produce. */
export function resultText(row: Pick<CampaignRow, 'result'>, locale: string): string {
  return row.result === null ? '—' : formatNumber(row.result, locale);
}
