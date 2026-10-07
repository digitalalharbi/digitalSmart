import { AlertTriangle, Info } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatNumber, formatRate } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Card, CardContent } from '@/shared/ui/card';
import { cn } from '@/shared/lib/cn';
import { formatMinor } from './money';
import type { AttributionCoverage } from './types';

type CoverageMeterProps = {
  coverage: AttributionCoverage;
};

/**
 * How much of the period the attributed figures account for.
 *
 * A **meter**, not a chart: one ratio against one limit, which is the single case where a filled track
 * says more than a bar chart would. The track is a lighter step of the fill's own ramp, so the state
 * reads across the whole width rather than only in the filled part.
 *
 * It leads the page rather than footnoting it. "Meta earned 180,000 riyals" is read as a share of the
 * month; at 40% coverage it is a share of a share, and the rest of the money is not spread among the
 * campaigns — it is unknown, which is a different thing from zero and a different thing again from a
 * campaign having earned nothing.
 */
export function CoverageMeter({ coverage }: CoverageMeterProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;

  const share = coverage.revenue_coverage ?? coverage.conversion_coverage;
  const unattributed = coverage.unattributed_revenue_minor;

  return (
    <Card>
      <CardContent className="flex flex-col gap-3 pt-5">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-content text-[0.9375rem] font-semibold">
              {t('attribution.coverage.title')}
            </h2>
            <Badge tone={coverage.is_reportable ? 'success' : 'warning'}>
              {coverage.is_reportable
                ? t('attribution.coverage.reportable')
                : t('attribution.coverage.thin', { threshold: coverage.reportable_threshold })}
            </Badge>
          </div>

          {/*
            The hero figure of this card, in proportional digits. `tabular-nums` makes a large
            standalone number look loose; it is for columns that align.
          */}
          <p className="text-content text-2xl font-semibold">
            {share === null ? '—' : formatRate(share, locale)}
          </p>
        </div>

        {/*
          The meter. Its track is a pale step of the same ramp rather than a neutral grey, so the
          unfilled part still reads as part of the same measurement.
        */}
        <div
          role="meter"
          aria-valuenow={share ?? 0}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-label={t('attribution.coverage.title')}
          className="h-2 w-full overflow-hidden rounded-full"
          style={{ backgroundColor: 'var(--ds-chart-step-1)' }}
        >
          <div
            className={cn('ease-standard h-full rounded-full transition-[width]')}
            style={{
              width: `${String(Math.max(0, Math.min(100, share ?? 0)))}%`,
              backgroundColor: coverage.is_reportable
                ? 'var(--ds-chart-step-5)'
                : 'var(--ds-warning)',
            }}
          />
        </div>

        <dl className="grid gap-3 sm:grid-cols-3">
          <div>
            <dt className="text-content-tertiary text-xs tracking-wide uppercase">
              {t('attribution.coverage.attributed')}
            </dt>
            <dd className="text-content text-sm tabular-nums">
              {coverage.attributed_revenue_minor === null || coverage.currency === null
                ? '—'
                : formatMinor(coverage.attributed_revenue_minor, coverage.currency, locale)}
            </dd>
          </div>
          <div>
            <dt className="text-content-tertiary text-xs tracking-wide uppercase">
              {t('attribution.coverage.unattributed')}
            </dt>
            <dd className="text-content text-sm tabular-nums">
              {unattributed === null || coverage.currency === null
                ? '—'
                : formatMinor(unattributed, coverage.currency, locale)}
            </dd>
          </div>
          <div>
            <dt className="text-content-tertiary text-xs tracking-wide uppercase">
              {t('attribution.coverage.weakest')}
            </dt>
            <dd className="text-content flex flex-wrap items-center gap-1.5 text-sm">
              {coverage.weakest_evidence_label}
              {coverage.confidence === null ? null : (
                <Badge tone="neutral">{formatNumber(coverage.confidence, locale)}%</Badge>
              )}
            </dd>
          </div>
        </dl>

        {/*
          The sentence a reader acts on. Shown whenever the figures describe too little of the period
          to lead with, which is most of the time in a real account — and that is the point.
        */}
        {coverage.is_reportable ? (
          <p className="text-content-secondary flex items-start gap-1.5 text-sm">
            <Info aria-hidden className="mt-0.5 size-3.5 shrink-0" />
            {t('attribution.coverage.explain', {
              named: formatRate(coverage.campaign_named_share ?? 0, locale),
            })}
          </p>
        ) : (
          <Alert tone="warning" className="flex items-start gap-2">
            <AlertTriangle aria-hidden className="mt-0.5 size-4 shrink-0" />
            <span>{t('attribution.coverage.warning')}</span>
          </Alert>
        )}
      </CardContent>
    </Card>
  );
}
