import { useTranslation } from 'react-i18next';
import { CreativeMediaTile } from '@/features/creatives';
import { cn } from '@/shared/lib/cn';
import { localeTag } from '@/shared/lib/format';
import { formatMetric, MISSING } from '@/shared/metrics';
import { Sparkline } from '@/shared/ui/viz';
import type { CreativeSectionRow, RenderedSection } from './types';

/** The figures the screen puts beside the picture, in the order a client asks about them. */
const FIGURES: Array<{ key: string; label: string }> = [
  { key: 'spend', label: 'creatives.spend' },
  { key: 'ctr', label: 'CTR' },
  { key: 'cpa', label: 'CPA' },
  { key: 'roas', label: 'ROAS' },
  { key: 'conversions', label: 'reports.creative.results' },
  { key: 'frequency', label: 'creatives.frequency' },
];

/** Shown only where the figure is valid: a ratio nobody could compute is left out, not shown as a dash. */
const ONLY_WHEN_VALID = new Set(['roas', 'frequency']);

/**
 * One creative, large: the picture on one side, its figures and click-through trend on the other (stacked on a phone).
 *
 * The picture is the same tile the report page uses — a real address the server vouched for, or a plain statement that
 * there is no preview. A video is its poster only: nothing autoplays in a room, and a clip that does not load in front
 * of a client is worse than a still that does. A figure the platform did not report is a dash, and ROAS and frequency are
 * left out entirely for a creative that has neither rather than shown as a dash it was never going to fill.
 */
export function CreativeSlide({
  section,
  row,
  n,
  of,
}: {
  section: RenderedSection;
  row: CreativeSectionRow;
  n: number;
  of: number;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const number = new Intl.NumberFormat(localeTag(locale));
  const percent = new Intl.NumberFormat(localeTag(locale), {
    style: 'percent',
    maximumFractionDigits: 0,
    signDisplay: 'exceptZero',
  });
  const figures = FIGURES.filter(
    ({ key }) => !ONLY_WHEN_VALID.has(key) || row.metrics.values[key]?.value != null,
  );
  const changes = row.evidence.flatMap(({ signal, value }) =>
    signal === 'ctr_change' && typeof value === 'number' ? [`CTR ${percent.format(value)}`] : [],
  );

  return (
    <>
      <h2 className="text-content-secondary text-xl font-medium" dir="auto">
        {section.title}
        <span className="text-content-tertiary ds-numeric ms-3 text-base">
          {number.format(n)} / {number.format(of)}
        </span>
      </h2>
      {/* The creative dominates: roughly two thirds of the width on a desktop, the whole width first on a phone. */}
      <div className="grid items-center gap-6 lg:grid-cols-[minmax(0,13fr)_minmax(0,8fr)]">
        <CreativeMediaTile
          media={row.media}
          format={row.format}
          label={row.label}
          className="rounded-3xl"
        />
        <div className="flex min-w-0 flex-col gap-4">
          <div className="min-w-0">
            <p className="text-content truncate text-2xl font-semibold" dir="auto">
              {row.label}
            </p>
            <p className="text-content-secondary truncate text-base" dir="auto">
              {row.platform_label} · {row.format_label}
              {row.campaigns[0] === undefined ? '' : ` · ${row.campaigns[0]}`}
            </p>
          </div>
          {row.verdict === null || row.verdict === 'stable' ? null : (
            <span className="bg-surface-sunken text-content w-fit rounded-full px-3 py-1 text-sm font-medium">
              {t(`creatives.verdict.${row.verdict}`)}
              {changes.length === 0 ? null : (
                <bdi dir="ltr" className="text-content-secondary ms-2">
                  {changes.join(' · ')}
                </bdi>
              )}
            </span>
          )}
          <dl className="grid grid-cols-2 gap-3">
            {figures.map(({ key, label }) => {
              const value = row.metrics.values[key];

              return (
                <div
                  key={key}
                  className="bg-surface flex min-w-0 flex-col gap-1 rounded-2xl p-4 shadow-xs"
                >
                  <dt className="text-content-tertiary ds-overline truncate">
                    {label.includes('.') ? t(label) : label}
                  </dt>
                  <dd
                    className={cn(
                      'ds-figure truncate',
                      value?.value == null
                        ? 'text-content-tertiary text-2xl'
                        : 'text-content text-3xl',
                    )}
                  >
                    {value === undefined ? MISSING : formatMetric(value, locale, true)}
                  </dd>
                </div>
              );
            })}
          </dl>
          <Sparkline
            values={row.trend.map((point) => point.ctr)}
            className="text-content-secondary"
            width={240}
            height={44}
          />
        </div>
      </div>
    </>
  );
}
