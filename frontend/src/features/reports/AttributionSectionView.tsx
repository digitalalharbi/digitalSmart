import { useTranslation } from 'react-i18next';
import { formatMinor, localeTag } from '@/shared/lib/format';
import { MISSING } from '@/shared/metrics';
import { ChartFrame } from '@/shared/ui/chart';
import { Badge } from '@/shared/ui/badge';
import { ScrollRegion } from '@/shared/ui/scroll-region';
import type { RenderedSection } from './types';

/**
 * Which campaigns a client's orders came from — internal only.
 *
 * Coverage leads, because an attributed figure read as a total is the specific way this section could mislead: the
 * share of the period's revenue the rows explain is the first thing on it, amber below the reportable line. A figure the
 * server did not send is "not available", never zero, and the revenue that arrived with no marketing information is said
 * to be unknown rather than divided among the campaigns.
 */
export function AttributionSectionView({ section }: { section: RenderedSection }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const data = section.attribution;

  if (data == null) {
    return null;
  }

  const pct = (value: number | null): string =>
    value === null
      ? MISSING
      : new Intl.NumberFormat(localeTag(locale), {
          style: 'percent',
          maximumFractionDigits: 1,
        }).format(value / 100);
  const money = (minor: number | null, currency: string | null): string =>
    minor === null || currency === null ? MISSING : formatMinor(minor, currency, locale);
  const coverage = data.coverage;

  return (
    <ChartFrame
      title={section.title}
      caveats={section.caveats}
      isEmpty={section.is_empty}
      emptyTitle={t('reports.section.emptyTitle')}
      emptyBody={t('reports.section.emptyBody')}
      table={{
        columns: [
          t('reports.attribution.campaign'),
          t('reports.attribution.conversions'),
          t('reports.attribution.revenue'),
        ],
        rows: data.rows.map((row, index) => ({
          key: `${row.label}-${String(index)}`,
          label: row.label,
          cells: [String(row.conversions), money(row.revenue_minor, row.currency)],
        })),
      }}
    >
      <div className="flex flex-col gap-4">
        <p className="text-content-secondary text-xs">
          {t('reports.attribution.according', { source: data.revenue_source_label })}
        </p>

        <div
          className={
            coverage.is_reportable
              ? 'bg-surface-sunken flex flex-wrap items-center gap-3 rounded-2xl p-4'
              : 'bg-warning-surface text-warning flex flex-wrap items-center gap-3 rounded-2xl p-4'
          }
        >
          <span className="ds-figure text-2xl">{pct(coverage.revenue_coverage)}</span>
          <span className="text-sm">
            {t('reports.attribution.coverage', { percent: coverage.reportable_percent })}
          </span>
          <Badge tone={coverage.is_reportable ? 'success' : 'warning'}>
            {t(
              coverage.is_reportable
                ? 'reports.attribution.reportable'
                : 'reports.attribution.sample',
            )}
          </Badge>
        </div>

        <ScrollRegion label={t('reports.attribution.campaigns')}>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-content-tertiary ds-overline text-start">
                <th scope="col" className="py-2 pe-3 text-start">
                  {t('reports.attribution.campaign')}
                </th>
                <th scope="col" className="py-2 pe-3 text-end">
                  {t('reports.attribution.conversions')}
                </th>
                <th scope="col" className="py-2 pe-3 text-end">
                  {t('reports.attribution.revenue')}
                </th>
                <th scope="col" className="py-2 text-end">
                  {t('reports.attribution.roas')}
                </th>
              </tr>
            </thead>
            <tbody>
              {data.rows.map((row, index) => (
                <tr key={`${row.label}-${String(index)}`} className="border-line-subtle border-t">
                  <th scope="row" className="text-content py-2 pe-3 text-start font-medium">
                    {row.label}
                  </th>
                  <td className="ds-numeric py-2 pe-3 text-end">{row.conversions}</td>
                  <td className="ds-numeric py-2 pe-3 text-end">
                    {money(row.revenue_minor, row.currency)}
                  </td>
                  <td className="ds-numeric py-2 text-end">
                    {row.roas === null ? MISSING : `${row.roas.toFixed(2)}×`}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </ScrollRegion>

        {coverage.unattributed_revenue_minor === null ? null : (
          <p className="text-content-secondary text-xs">
            {t('reports.attribution.unknown', {
              amount: money(
                coverage.unattributed_revenue_minor,
                data.rows.find((row) => row.currency !== null)?.currency ?? data.spend_currency,
              ),
            })}
          </p>
        )}
      </div>
    </ChartFrame>
  );
}
