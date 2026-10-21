import { AlertTriangle, Sparkles, TrendingDown, TrendingUp, Trophy } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { formatMetric, MISSING } from '@/shared/metrics';
import type { MetricValue } from '@/shared/metrics';
import { Sparkline } from '@/shared/ui/viz';
import { CreativeMediaTile } from './CreativeMediaTile';
import { evidenceChips } from './evidence';
import type { CreativeRow, CreativeVerdict } from './types';

const VERDICT_ICON: Record<CreativeVerdict, typeof TrendingUp> = {
  top: Trophy,
  rising: TrendingUp,
  stable: Sparkles,
  fatiguing: TrendingDown,
  weak: AlertTriangle,
};

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex min-w-0 flex-col">
      <dt className="text-content-tertiary ds-overline truncate">{label}</dt>
      <dd className="ds-numeric text-content truncate text-sm">{value}</dd>
    </div>
  );
}

/**
 * One creative, media first.
 *
 * The picture is the card; beneath it the four figures that decide whether it earns its place, a spark of its daily
 * click-through, and — only when the verdict is not "steady" — why. A figure the platform did not report is a dash,
 * never a zero.
 */
export function CreativeCard({
  row,
  chosen,
  canChoose,
  onToggle,
}: {
  row: CreativeRow;
  chosen: boolean;
  canChoose: boolean;
  onToggle: () => void;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const show = (value: MetricValue | undefined): string =>
    value === undefined ? MISSING : formatMetric(value, locale, true);
  const values = row.metrics.values;
  const Icon = row.verdict === null ? null : VERDICT_ICON[row.verdict];
  const chips = evidenceChips(row, locale, t);
  const frequency = values['frequency'];
  const returnMetric = values['roas']?.value == null ? values['cpa'] : values['roas'];

  return (
    <li
      className={cn(
        'border-line-subtle bg-surface flex min-w-0 flex-col overflow-hidden rounded-2xl border',
        chosen && 'border-accent-strong',
      )}
    >
      <div className="relative">
        <CreativeMediaTile media={row.media} format={row.format} label={row.label} />
        {row.verdict !== null && Icon !== null && row.verdict !== 'stable' ? (
          <span className="bg-surface text-content absolute start-2 top-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium">
            <Icon aria-hidden className="size-3" />
            {t(`creatives.verdict.${row.verdict}`)}
          </span>
        ) : null}
        <span className="bg-surface text-content-secondary absolute end-2 top-2 rounded-full px-2 py-0.5 text-xs">
          {row.platform_label}
        </span>
      </div>

      <div className="flex min-w-0 flex-col gap-2 p-3">
        <div className="min-w-0">
          <p className="text-content truncate text-sm font-medium" dir="auto">
            {row.label}
          </p>
          <p className="text-content-tertiary truncate text-xs" dir="auto">
            {row.format_label}
            {row.ads > 1 ? ` · ${t('creatives.ads', { count: row.ads })}` : ''}
            {row.campaigns[0] === undefined ? '' : ` · ${row.campaigns[0]}`}
            {row.campaign_count > 1 ? ` +${row.campaign_count - 1}` : ''}
          </p>
        </div>

        <dl className="grid grid-cols-2 gap-x-3 gap-y-1.5">
          <Figure label={t('creatives.spend')} value={show(values['spend'])} />
          <Figure label="CTR" value={show(values['ctr'])} />
          <Figure
            label={values['roas']?.value == null ? 'CPA' : 'ROAS'}
            value={show(returnMetric)}
          />
          <Figure label={t('creatives.frequency')} value={show(frequency)} />
        </dl>

        <div className="flex items-center justify-between gap-2">
          <div className="flex min-w-0 flex-wrap gap-1">
            {chips.map((chip) => (
              <span
                key={chip}
                className="bg-surface-sunken text-content-secondary rounded-full px-2 py-0.5 text-xs"
              >
                <bdi dir="ltr">{chip}</bdi>
              </span>
            ))}
          </div>
          <Sparkline
            values={row.trend.map((point) => point.ctr)}
            className="text-content-secondary shrink-0"
          />
        </div>

        <label className="text-content-secondary flex items-center gap-2 text-xs">
          <input
            type="checkbox"
            checked={chosen}
            disabled={!chosen && !canChoose}
            onChange={onToggle}
          />
          {t('creatives.compare')}
          <span className="sr-only"> — {row.label}</span>
        </label>
      </div>
    </li>
  );
}
