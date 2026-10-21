import { useTranslation } from 'react-i18next';
import { formatMetric } from '@/shared/metrics';
import { RankingBars, Sparkline } from '@/shared/ui/viz';
import { CreativeMediaTile } from './CreativeMediaTile';
import type { CreativeRow } from './types';

/** The figures set side by side, and whether lower is the better. */
const COMPARED: Array<{ key: string; lowerIsBetter: boolean }> = [
  { key: 'spend', lowerIsBetter: false },
  { key: 'ctr', lowerIsBetter: false },
  { key: 'cpc', lowerIsBetter: true },
  { key: 'cpa', lowerIsBetter: true },
  { key: 'roas', lowerIsBetter: false },
  { key: 'conversions', lowerIsBetter: false },
];
const COLOURS = [
  'var(--ds-chart-1)',
  'var(--ds-chart-2)',
  'var(--ds-chart-3)',
  'var(--ds-chart-4)',
];

/**
 * Two to four creatives, one bar chart per figure — the winner is the bar you see.
 *
 * A figure only one of them has is not drawn: a bar against nothing is not a comparison. Each creative keeps its own
 * colour across every chart, so the eye follows a creative rather than re-reading a legend.
 */
export function CreativeComparison({ rows }: { rows: CreativeRow[] }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;

  return (
    <section
      aria-label={t('creatives.comparison')}
      className="bg-surface border-line-subtle flex flex-col gap-4 rounded-2xl border p-4"
    >
      <h2 className="text-content text-[0.9375rem] font-semibold">{t('creatives.comparison')}</h2>

      <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {rows.map((row, index) => (
          <li key={row.key} className="flex min-w-0 flex-col gap-1.5">
            <CreativeMediaTile
              media={row.media}
              format={row.format}
              label={row.label}
              className="rounded-xl"
            />
            <div className="flex items-center gap-1.5">
              <span
                aria-hidden
                className="size-2.5 shrink-0 rounded-full"
                style={{ background: COLOURS[index] }}
              />
              <span className="text-content truncate text-xs font-medium" dir="auto">
                {row.label}
              </span>
            </div>
            <Sparkline
              values={row.trend.map((point) => point.ctr)}
              className="text-content-secondary"
              width={120}
            />
          </li>
        ))}
      </ul>

      <div className="grid gap-4 md:grid-cols-2">
        {COMPARED.flatMap(({ key, lowerIsBetter }) => {
          const entries = rows.flatMap((row, index) => {
            const value = row.metrics.values[key];

            return value?.value == null ? [] : [{ row, index, value }];
          });

          if (entries.length < 2) {
            return [];
          }

          const best = entries.reduce((top, entry) =>
            (
              lowerIsBetter
                ? (entry.value.value as number) < (top.value.value as number)
                : (entry.value.value as number) > (top.value.value as number)
            )
              ? entry
              : top,
          );

          return [
            <div key={key} className="flex min-w-0 flex-col gap-2">
              <h3 className="text-content-secondary text-xs font-medium">
                {entries[0]?.value.label}
              </h3>
              <RankingBars
                label={entries[0]?.value.label ?? key}
                rows={entries.map(({ row, index, value }) => ({
                  key: row.key,
                  label: row.label,
                  value: value.value,
                  display: formatMetric(value, locale, true),
                  colour: COLOURS[index] ?? 'var(--ds-chart-1)',
                  ...(row.key === best.row.key ? { hint: t('creatives.best') } : {}),
                }))}
              />
            </div>,
          ];
        })}
      </div>
    </section>
  );
}
