import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatMinor } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Skeleton } from '@/shared/ui/skeleton';
import { useLinkPerformanceQuery } from './api';
import type { CostFigure, LinkPerformance, RevenueFigure, SpendBasis } from './types';

const COSTS = [
  'cpc',
  'cost_per_landing_view',
  'cost_per_form_start',
  'cpl',
  'cost_per_contacted_lead',
  'cpql',
  'cost_per_appointment',
  'cost_per_opportunity',
  'cac',
] as const;

const BASIS_TONE: Record<SpendBasis, 'success' | 'warning' | 'neutral'> = {
  DIRECT: 'success',
  ALLOCATED: 'warning',
  UNAVAILABLE: 'neutral',
};

/** A cost, or why there is none. A missing cost is never written as zero. */
function Cost({ label, figure }: { label: string; figure: CostFigure | undefined }) {
  const { t, i18n } = useTranslation();
  const has = figure !== undefined && figure.minor !== null && figure.currency !== null;

  return (
    <div className="min-w-0">
      <dt className="text-content-tertiary text-xs">{label}</dt>
      <dd className="text-content text-base font-semibold tabular-nums">
        {has ? (
          formatMinor(figure.minor as number, figure.currency as string, i18n.language)
        ) : (
          <span title={figure?.reason ?? undefined}>{t('tracking.links.notReported')}</span>
        )}
      </dd>
    </div>
  );
}

function Revenue({ source, figure }: { source: string; figure: RevenueFigure }) {
  const { t, i18n } = useTranslation();
  const has = figure.minor !== null && figure.currency !== null;

  return (
    <li className="border-line-subtle flex flex-col gap-1 rounded-lg border p-3">
      <span className="text-content-secondary text-xs">
        {t(`tracking.performance.revenue.${source}`, { defaultValue: source })}
      </span>
      <span className="text-content text-base font-semibold tabular-nums">
        {has
          ? formatMinor(figure.minor as number, figure.currency as string, i18n.language)
          : t('tracking.links.notReported')}
      </span>
      {has ? null : <span className="text-content-tertiary text-xs">{figure.reason}</span>}
      <span className="text-content-secondary text-xs">
        {t('tracking.performance.roas')}:{' '}
        {figure.roas.value === null
          ? t('tracking.links.notReported')
          : `${figure.roas.value.toFixed(2)}×`}
      </span>
      {figure.roas.value === null && figure.roas.reason ? (
        <span className="text-content-tertiary text-xs">{figure.roas.reason}</span>
      ) : null}
    </li>
  );
}

function Body({ data }: { data: LinkPerformance }) {
  const { t, i18n } = useTranslation();
  const spend = data.spend;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1.5">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-content-secondary text-xs">{t('tracking.performance.spend')}</span>
          <Badge tone={BASIS_TONE[spend.basis]}>
            {t(`tracking.performance.basis.${spend.basis}`)}
          </Badge>
          {spend.level ? (
            <span className="text-content-tertiary text-xs">
              {t('tracking.performance.level', {
                level: t(`tracking.performance.levels.${spend.level}`),
              })}
            </span>
          ) : null}
        </div>
        <p className="text-content text-xl font-semibold tabular-nums">
          {spend.minor !== null && spend.currency !== null
            ? formatMinor(spend.minor, spend.currency, i18n.language)
            : t('tracking.links.notReported')}
        </p>
        {spend.reason ? <p className="text-content-secondary text-xs">{spend.reason}</p> : null}
        {spend.allocation ? (
          <p className="text-content-secondary text-xs">
            {t('tracking.performance.allocation', {
              method: t(`tracking.performance.methods.${spend.allocation.method}`, {
                defaultValue: spend.allocation.method,
              }),
              share: Math.round(spend.allocation.share * 100),
              confidence: t(`tracking.performance.confidence.${spend.allocation.confidence}`, {
                defaultValue: spend.allocation.confidence,
              }),
            })}
          </p>
        ) : null}
      </div>

      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        {COSTS.map((key) => (
          <Cost key={key} label={t(`tracking.performance.costs.${key}`)} figure={data.costs[key]} />
        ))}
      </dl>

      <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {Object.entries(data.revenue).map(([source, figure]) => (
          <Revenue key={source} source={source} figure={figure} />
        ))}
      </ul>
      <p className="text-content-tertiary text-xs">{t('tracking.performance.sourcesNote')}</p>
    </div>
  );
}

/** What a link cost and earned over the last thirty days, read only when opened. */
export function LinkPerformancePanel({ linkId }: { linkId: string }) {
  const { t } = useTranslation();
  const [allocate, setAllocate] = useState(false);
  const performance = useLinkPerformanceQuery(linkId, 30, allocate, true);

  return (
    <div className="flex flex-col gap-3">
      {performance.isPending ? (
        <Skeleton className="h-32 w-full" />
      ) : performance.isError ? (
        <Alert tone="danger">{t('tracking.performance.failed')}</Alert>
      ) : (
        <Body data={performance.data} />
      )}
      <label className="flex min-h-11 items-center gap-2 text-xs">
        <input
          type="checkbox"
          checked={allocate}
          onChange={(e) => setAllocate(e.target.checked)}
          className="size-4"
        />
        {t('tracking.performance.allocate')}
      </label>
    </div>
  );
}
