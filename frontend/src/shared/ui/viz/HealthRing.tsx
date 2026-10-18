import { useTranslation } from 'react-i18next';
import { DonutChart } from './DonutChart';
import { HEALTH_COLOUR } from './colours';

export type HealthCounts = {
  healthy: number;
  watch: number;
  needs_attention: number;
  critical: number;
  inactive: number;
  insufficient_data: number;
};

const ORDER = [
  'critical',
  'needs_attention',
  'watch',
  'healthy',
  'insufficient_data',
  'inactive',
] as const;

/**
 * A small ring of a client's, project's or campaign set's health, the share that is healthy in the middle.
 *
 * Inactive campaigns are left out of the share: a paused campaign is not unhealthy, and counting it would make an
 * agency that pauses work for the season look like it is failing.
 */
export function HealthRing({
  counts,
  size = 64,
  thickness = 9,
  label,
}: {
  counts: HealthCounts;
  size?: number;
  thickness?: number;
  label: string;
}) {
  const { t } = useTranslation();
  const assessed = counts.healthy + counts.watch + counts.needs_attention + counts.critical;
  const share = assessed === 0 ? null : Math.round((counts.healthy / assessed) * 100);

  return (
    <DonutChart
      legend={false}
      size={size}
      thickness={thickness}
      label={`${label}: ${ORDER.map((key) => `${t(`health.${key === 'needs_attention' ? 'attention' : key === 'insufficient_data' ? 'unknown' : key}`)} ${String(counts[key])}`).join(', ')}`}
      centre={{ value: share === null ? '—' : `${String(share)}%` }}
      segments={ORDER.map((key) => ({
        key,
        label: key,
        value: counts[key],
        colour: HEALTH_COLOUR[key],
      }))}
    />
  );
}
