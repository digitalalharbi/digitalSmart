import { useTranslation } from 'react-i18next';
import { localeTag } from '@/shared/lib/format';
import { MISSING } from '@/shared/metrics';
import { ChartFrame } from '@/shared/ui/chart';
import { ScrollRegion } from '@/shared/ui/scroll-region';
import type { RenderedSection } from './types';

/**
 * One fixed table of the client's first-party tracking: its links, forms, funnel, lead follow-up or health.
 *
 * The server sends counts and states only — no address, token, lead or answer — so there is nothing here to hide. A cell
 * it did not report is "not reported", never zero: a funnel step no page sent is a gap, and a gap is not a loss.
 */
export function TrackingSectionView({ section }: { section: RenderedSection }) {
  const { t, i18n } = useTranslation();
  const data = section.tracking;

  if (data == null) {
    return null;
  }

  const number = new Intl.NumberFormat(localeTag(i18n.language), { maximumFractionDigits: 1 });
  const cell = (value: string | number | null | undefined): string =>
    value === null || value === undefined
      ? MISSING
      : typeof value === 'number'
        ? number.format(value)
        : value;
  const first = data.columns[0]?.key ?? '';

  return (
    <ChartFrame
      title={section.title}
      caveats={section.caveats}
      isEmpty={section.is_empty}
      emptyTitle={t('reports.section.emptyTitle')}
      emptyBody={t('reports.section.emptyBody')}
      table={{
        columns: data.columns.slice(1).map((column) => column.label),
        rows: data.rows.map((row, index) => ({
          key: `${String(row[first] ?? '')}-${String(index)}`,
          label: cell(row[first]),
          cells: data.columns.slice(1).map((column) => cell(row[column.key])),
        })),
      }}
    >
      <ScrollRegion label={section.title}>
        <table className="w-full min-w-max text-sm">
          <thead>
            <tr className="text-content-tertiary text-start text-xs">
              {data.columns.map((column) => (
                <th key={column.key} scope="col" className="px-3 py-2 text-start font-medium">
                  {column.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data.rows.map((row, index) => (
              <tr key={index} className="border-line-subtle border-t">
                {data.columns.map((column) => (
                  <td key={column.key} className="px-3 py-2 tabular-nums">
                    {cell(row[column.key])}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </ScrollRegion>
    </ChartFrame>
  );
}
