import {
  AlertTriangle,
  CircleDot,
  FlaskConical,
  Hand,
  History,
  PieChart,
  Sparkles,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/shared/ui/badge';
import type { MetricSourceValue } from '@/shared/metrics';

/**
 * Says where a number came from.
 *
 * Not decoration. Every figure in this product carries a source state, and this is
 * how it reaches the reader: a sandbox total must be visibly a sandbox total, and
 * a forecast must be visibly a forecast. A chart with no source state is a chart
 * that can lie, so this component renders for everything except `live` — where the
 * absence of a badge is itself the statement.
 */
const TONE: Record<
  MetricSourceValue,
  'neutral' | 'accent' | 'success' | 'warning' | 'danger' | 'info'
> = {
  live: 'success',
  synced: 'neutral',
  stale: 'warning',
  partial: 'warning',
  // Deliberately the loudest tone available: a sandbox figure mistaken for a
  // client's spend is the failure this whole mechanism exists to prevent.
  sandbox: 'danger',
  manual: 'info',
  estimated: 'info',
};

const ICON: Record<MetricSourceValue, typeof CircleDot> = {
  live: CircleDot,
  synced: CircleDot,
  stale: History,
  partial: PieChart,
  sandbox: FlaskConical,
  manual: Hand,
  estimated: Sparkles,
};

type SourceBadgeProps = {
  source: MetricSourceValue;
  /** Render even for `live`, where a badge is normally unnecessary noise. */
  alwaysShow?: boolean;
  className?: string;
};

export function SourceBadge({ source, alwaysShow = false, className }: SourceBadgeProps) {
  const { t } = useTranslation();

  if (source === 'live' && !alwaysShow) {
    return null;
  }

  const Icon = ICON[source];

  return (
    <Badge
      tone={TONE[source]}
      className={className}
      // The label says what it is; the title says what it means for the reader.
      title={t(`analytics.source.${source}.help`)}
    >
      <Icon aria-hidden className="size-3" />
      {t(`analytics.source.${source}.label`)}
    </Badge>
  );
}

/**
 * A full-width explanation, for the top of a surface rather than beside a figure.
 *
 * Shown once per screen so a reader meets the caveat before the numbers, not after
 * they have already drawn a conclusion from them.
 */
export function SourceNotice({ source }: { source: MetricSourceValue }) {
  const { t } = useTranslation();

  if (source === 'live' || source === 'synced') {
    return null;
  }

  const tone =
    source === 'sandbox'
      ? 'border-danger/30 bg-danger-surface text-danger'
      : 'border-warning/30 bg-warning-surface text-warning';

  return (
    <div
      role="status"
      className={`flex items-start gap-2.5 rounded-lg border px-3.5 py-2.5 text-sm ${tone}`}
    >
      <AlertTriangle aria-hidden className="mt-0.5 size-4 shrink-0" />
      <p className="leading-relaxed">
        <strong className="font-semibold">{t(`analytics.source.${source}.label`)}</strong>{' '}
        {t(`analytics.source.${source}.notice`)}
      </p>
    </div>
  );
}
