import { useTranslation } from 'react-i18next';
import { formatNumber, formatPercent } from '@/shared/lib/format';
import { formatDuration } from './duration';
import type { FunnelAnalytics, StageResult } from './types';

/**
 * A funnel, drawn as bars that never lie about a gap.
 *
 * A step with a count is a bar sized against the first step. A step the destination page did not report has no bar and says
 * so — a short bar would read as "most people left here" when the truth is "this product was not told". Rates sit beside the
 * bar, and when one cannot be meaningful the reason is shown instead of a number.
 */
export function FunnelView({ funnel }: { funnel: FunnelAnalytics }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const first = funnel.stages.find((s) => s.count !== null)?.count ?? 0;
  const label = (s: StageResult) =>
    (locale.startsWith('ar') ? s.label_ar : s.label_en) ??
    t(`tracking.funnels.sources.${s.source}`);

  return (
    <div className="flex flex-col gap-3">
      <ol className="flex flex-col gap-2" aria-label={t('tracking.funnels.title')}>
        {funnel.stages.map((stage, index) => {
          const width =
            stage.count !== null && first > 0
              ? Math.max(2, Math.round((stage.count / first) * 100))
              : 0;

          return (
            <li
              key={stage.source}
              className="bg-surface-sunken grid gap-1 rounded-2xl p-3 sm:grid-cols-[minmax(0,12rem)_1fr_auto] sm:items-center sm:gap-4"
            >
              <div className="min-w-0">
                <p className="text-content truncate text-sm font-medium">{label(stage)}</p>
                <p className="text-content-tertiary text-xs">
                  {t(`tracking.funnels.units.${stage.unit}`)}
                </p>
              </div>

              <div className="min-w-0">
                {stage.count === null ? (
                  <p className="text-warning text-xs">{stage.reason}</p>
                ) : (
                  <div
                    className="bg-surface h-3 w-full overflow-hidden rounded-full"
                    aria-hidden="true"
                  >
                    <div
                      className="bg-accent h-full rounded-full"
                      style={{ width: `${String(width)}%` }}
                    />
                  </div>
                )}
              </div>

              <dl className="flex flex-wrap items-baseline gap-x-4 gap-y-1 sm:justify-end">
                <div>
                  <dt className="sr-only">{t('tracking.funnels.count')}</dt>
                  <dd className="text-content text-lg font-semibold tabular-nums">
                    {stage.count === null
                      ? t('tracking.links.notReported')
                      : formatNumber(stage.count, 'en')}
                  </dd>
                </div>
                {index === 0 ? null : (
                  <div>
                    <dt className="text-content-tertiary text-xs">
                      {t('tracking.funnels.conversion')}
                    </dt>
                    <dd
                      className="text-content text-sm tabular-nums"
                      title={stage.conversion_reason ?? undefined}
                    >
                      {stage.conversion_from_previous === null
                        ? '—'
                        : formatPercent(stage.conversion_from_previous, 'en', 1)}
                    </dd>
                  </div>
                )}
                {index === 0 || stage.drop_off === null ? null : (
                  <div>
                    <dt className="text-content-tertiary text-xs">
                      {t('tracking.funnels.dropOff')}
                    </dt>
                    <dd className="text-content text-sm tabular-nums">
                      {formatNumber(stage.drop_off, 'en')}
                    </dd>
                  </div>
                )}
                {index === 0 || stage.median_seconds_from_previous === null ? null : (
                  <div>
                    <dt className="text-content-tertiary text-xs">
                      {t('tracking.funnels.median')}
                    </dt>
                    <dd className="text-content text-sm tabular-nums">
                      {formatDuration(stage.median_seconds_from_previous, locale)}
                    </dd>
                  </div>
                )}
              </dl>

              {index > 0 && stage.conversion_reason !== null && stage.count !== null ? (
                <p className="text-warning text-xs sm:col-span-3">{stage.conversion_reason}</p>
              ) : null}
            </li>
          );
        })}
      </ol>

      <p className="text-content-secondary text-sm">
        {funnel.overall_conversion === null
          ? funnel.overall_reason
          : t('tracking.funnels.overall', {
              value: formatPercent(funnel.overall_conversion, 'en', 1),
            })}
      </p>
      {funnel.excluded_sessions > 0 ? (
        <p className="text-content-tertiary text-xs">
          {t('tracking.funnels.excluded', { count: funnel.excluded_sessions })}
        </p>
      ) : null}
      {funnel.truncated ? (
        <p className="text-warning text-xs">{t('tracking.funnels.truncated')}</p>
      ) : null}
    </div>
  );
}
