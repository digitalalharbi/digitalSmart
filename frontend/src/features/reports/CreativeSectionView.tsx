import { useTranslation } from 'react-i18next';
import { CreativeCard, CreativeComparison, type CreativeRow } from '@/features/creatives';
import { localeTag } from '@/shared/lib/format';
import { formatMetric, MISSING } from '@/shared/metrics';
import { ChartFrame } from '@/shared/ui/chart';
import { ScrollRegion } from '@/shared/ui/scroll-region';
import { DonutChart } from '@/shared/ui/viz';
import type { CreativeSectionRow, RenderedSection } from './types';

const SERIES = ['var(--ds-chart-1)', 'var(--ds-chart-2)', 'var(--ds-chart-3)', 'var(--ds-chart-4)'];

/**
 * A report row is a creative with a position and no identifier. The shared components read the Creatives page's shape,
 * so the position stands in for the key (it is only ever a React key and a comparison colour here).
 */
function asCreative(row: CreativeSectionRow): CreativeRow {
  return {
    key: String(row.position),
    label: row.label,
    platform: row.platform,
    platform_label: row.platform_label,
    format: row.format,
    format_label: row.format_label,
    media: row.media,
    ads: row.ads,
    campaigns: row.campaigns,
    campaign_count: row.campaign_count,
    ad_name: null,
    metrics: { ...row.metrics, source_label: '' },
    trend: row.trend,
    verdict: row.verdict,
    verdict_label: row.verdict_label,
    evidence: row.evidence,
  };
}

/**
 * One creative block of a report — top creatives, performance, format mix, comparison or the ones to refresh.
 *
 * Pictures first. A tile draws only an address the server vouched for, and otherwise says there is no preview; it never
 * invents one. Every figure is read from the report's own payload through the one metric formatter, and a figure the
 * platform did not report is a dash. The table twin carries the same rows for anyone who cannot see the pictures.
 */
export function CreativeSectionView({ section }: { section: RenderedSection }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const creative = section.creative;

  if (creative == null) {
    return null;
  }

  const rows = creative.rows.map(asCreative);
  const show = (row: CreativeSectionRow, metric: string): string => {
    const value = row.metrics.values[metric];

    return value === undefined ? MISSING : formatMetric(value, locale, true);
  };
  const total = creative.mix.reduce((sum, entry) => sum + entry.value, 0);

  return (
    <ChartFrame
      title={section.title}
      caveats={section.caveats}
      isEmpty={section.is_empty}
      emptyTitle={t('reports.creative.emptyTitle')}
      emptyBody={t('reports.creative.emptyBody')}
      table={{
        columns: [
          t('reports.creative.platform'),
          t('reports.creative.format'),
          t('creatives.spend'),
          'CTR',
          t('reports.creative.results'),
          t('reports.creative.verdict'),
        ],
        rows: creative.rows.map((row) => ({
          key: String(row.position),
          label: row.label,
          cells: [
            row.platform_label,
            row.format_label,
            show(row, 'spend'),
            show(row, 'ctr'),
            show(row, 'conversions'),
            row.verdict_label ?? MISSING,
          ],
        })),
      }}
    >
      {creative.view === 'format_mix' ? (
        total <= 0 ? null : (
          <div className="flex flex-col gap-2">
            <p className="text-content-tertiary text-xs">
              {t(creative.mix_is_spend ? 'creatives.bySpend' : 'creatives.byCount')}
            </p>
            <DonutChart
              label={section.title}
              segments={creative.mix
                .filter((entry) => entry.value > 0)
                .map((entry, index) => ({
                  key: entry.key,
                  label: entry.label,
                  value: entry.value,
                  colour: SERIES[index] ?? 'var(--ds-chart-4)',
                }))}
              format={(value) =>
                new Intl.NumberFormat(localeTag(locale), {
                  style: 'percent',
                  maximumFractionDigits: 0,
                }).format(value / total)
              }
            />
          </div>
        )
      ) : creative.view === 'comparison' ? (
        rows.length < 2 ? (
          <p className="text-content-tertiary text-sm">{t('reports.creative.needTwo')}</p>
        ) : (
          <CreativeComparison rows={rows} />
        )
      ) : creative.view === 'performance' ? (
        <ScrollRegion label={section.title}>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-content-tertiary ds-overline text-start">
                <th className="py-2 pe-3 text-start font-medium">
                  {t('reports.creative.creative')}
                </th>
                <th className="px-3 text-end font-medium">{t('creatives.spend')}</th>
                <th className="px-3 text-end font-medium">CTR</th>
                <th className="px-3 text-end font-medium">{t('reports.creative.results')}</th>
                <th className="ps-3 text-start font-medium">{t('reports.creative.verdict')}</th>
              </tr>
            </thead>
            <tbody>
              {creative.rows.map((row) => (
                <tr key={row.position} className="border-line-subtle border-t">
                  <td className="py-2 pe-3">
                    <span className="block max-w-56 truncate" dir="auto">
                      {row.label}
                    </span>
                    <span className="text-content-tertiary block text-xs">
                      {row.platform_label} · {row.format_label}
                    </span>
                  </td>
                  <td className="ds-numeric px-3 text-end">{show(row, 'spend')}</td>
                  <td className="ds-numeric px-3 text-end">{show(row, 'ctr')}</td>
                  <td className="ds-numeric px-3 text-end">{show(row, 'conversions')}</td>
                  <td className="ps-3">{row.verdict_label ?? MISSING}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ScrollRegion>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {rows.map((row) => (
            <CreativeCard key={row.key} row={row} />
          ))}
        </ul>
      )}
    </ChartFrame>
  );
}
