import { useTranslation } from 'react-i18next';
import { Badge } from '@/shared/ui/badge';
import { Card } from '@/shared/ui/card';
import { RankingBars } from '@/shared/ui/viz';
import { formatMinor } from '@/shared/lib/format';
import type { RevenueBreakdownRow } from './types';

/**
 * One revenue breakdown — a channel, a campaign, a product.
 *
 * Two things this table insists on:
 *
 *  - **Every attributed row shows the weakest evidence it rests on, with its confidence.** A row crediting
 *    a campaign on a coupon code is a different claim from one crediting it on a click identifier, and
 *    rendering both identically would invite a reader to act on the weaker one.
 *  - **The unattributed row is marked, not hidden.** It is usually the largest, and a reader who cannot
 *    see it will read every other row as a larger share of the business than it is.
 */
export function BreakdownTable({
  title,
  rows,
  currency,
  locale,
  showUnits = false,
  hideTitle = false,
}: {
  title: string;
  rows: RevenueBreakdownRow[];
  currency: string;
  locale: string;
  showUnits?: boolean;
  hideTitle?: boolean;
}) {
  const { t } = useTranslation();

  if (rows.length === 0) {
    return (
      <Card className="p-4">
        {hideTitle ? null : <p className="text-content text-sm font-medium">{title}</p>}
        <p className="text-content-secondary mt-2 text-sm">{t('commerce.breakdown.empty')}</p>
      </Card>
    );
  }

  const ranked = [...rows].sort((x, y) => y.net_revenue_minor - x.net_revenue_minor).slice(0, 6);

  return (
    <Card role="group" aria-label={title} className="flex flex-col gap-4 p-4">
      {hideTitle ? null : <p className="text-content text-sm font-medium">{title}</p>}
      {/* The picture first: who earned most, by how much. The exact rows, with their evidence, are one press away. */}
      <RankingBars
        label={title}
        rows={ranked.map((row) => ({
          key: row.label,
          label: row.is_unattributed ? t('commerce.breakdown.unattributed') : row.label,
          value: row.net_revenue_minor,
          display: formatMinor(row.net_revenue_minor, currency, locale),
          colour: row.is_unattributed ? 'var(--ds-neutral-400)' : undefined,
        }))}
      />
      <details className="group/table">
        <summary className="text-content-secondary hover:text-content cursor-pointer list-none text-xs font-medium [&::-webkit-details-marker]:hidden">
          {t('commerce.breakdown.showTable', { count: rows.length })}
        </summary>
        <div className="mt-3 overflow-x-auto" role="group" aria-label={title} tabIndex={0}>
          <table className="w-full text-sm">
            <caption className="sr-only">{title}</caption>
            <thead>
              <tr className="text-content-tertiary border-line-subtle border-b text-xs">
                <th scope="col" className="px-4 py-2 text-start font-medium">
                  {t('commerce.breakdown.row')}
                </th>
                <th scope="col" className="px-4 py-2 text-end font-medium">
                  {t('commerce.breakdown.orders')}
                </th>
                {showUnits ? (
                  <th scope="col" className="px-4 py-2 text-end font-medium">
                    {t('commerce.breakdown.units')}
                  </th>
                ) : null}
                <th scope="col" className="px-4 py-2 text-end font-medium">
                  {t('commerce.breakdown.revenue')}
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.label} className="border-line-subtle border-b last:border-0">
                  <td className="px-4 py-2">
                    <div className="flex flex-wrap items-center gap-1.5">
                      <span
                        className={row.is_unattributed ? 'text-content-secondary' : 'text-content'}
                      >
                        {row.is_unattributed ? t('commerce.breakdown.unattributed') : row.label}
                      </span>

                      {/*
                       * The weakest link in the row, named. Absent on an unattributed row, where the label
                       * already says there is nothing to rest on.
                       */}
                      {row.is_unattributed ? null : row.evidence_label === null ? null : (
                        <Badge tone={confidenceTone(row.confidence)}>
                          {t('commerce.breakdown.evidence', {
                            evidence: row.evidence_label,
                            confidence: row.confidence ?? 0,
                          })}
                        </Badge>
                      )}
                    </div>
                  </td>
                  <td className="text-content-secondary px-4 py-2 text-end tabular-nums">
                    {new Intl.NumberFormat(locale).format(row.orders)}
                  </td>
                  {showUnits ? (
                    <td className="text-content-secondary px-4 py-2 text-end tabular-nums">
                      {new Intl.NumberFormat(locale).format(row.units)}
                    </td>
                  ) : null}
                  <td className="text-content px-4 py-2 text-end font-medium tabular-nums">
                    {formatMinor(row.net_revenue_minor, currency, locale)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </Card>
  );
}

/**
 * How much weight the row's confidence deserves on screen.
 *
 * A coupon-backed row is shown as a warning rather than as neutral, because the number beside it is the
 * one most likely to be acted on wrongly.
 */
function confidenceTone(confidence: number | null): 'neutral' | 'success' | 'warning' {
  if (confidence === null) {
    return 'neutral';
  }

  return confidence >= 70 ? 'success' : 'warning';
}
