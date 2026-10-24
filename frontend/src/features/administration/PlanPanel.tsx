import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api } from '@/shared/api/client';
import { formatNumber, localeTag, readableSize } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Skeleton } from '@/shared/ui/skeleton';
import { ProgressBar } from '@/shared/ui/viz';

export type WorkspacePlan = {
  plan: { code: string; name: string };
  features: Array<{ key: string; enabled: boolean; source: string; everywhere: boolean }>;
  limits: Array<{
    key: string;
    allowed: number | null;
    used: number | null;
    source: string;
    monthly: boolean;
    resets_at: string | null;
    over: boolean;
    refuses: boolean;
  }>;
  overrides: Array<{
    kind: string;
    key: string;
    value: string;
    previous_value: string | null;
    reason: string;
    changed_by: string;
    at: string;
  }>;
};

/**
 * What this workspace's plan includes and how much of each limit is used.
 *
 * Read-only: a plan is changed by the platform operator, not from inside the workspace. A limit that cannot be measured yet says
 * so instead of showing a zero, and a workspace over its plan after a downgrade is told that nothing was removed.
 */
export function PlanPanel() {
  const { t, i18n } = useTranslation();
  const { data, isPending, isError } = useQuery({
    queryKey: ['administration', 'plan'] as const,
    queryFn: async (): Promise<WorkspacePlan> => {
      const { data: body } = await api.get<{ data: WorkspacePlan }>('/workspace/plan');

      return body.data;
    },
    staleTime: 60_000,
  });

  if (isError) {
    return <Alert tone="danger">{t('plan.failed')}</Alert>;
  }

  if (isPending) {
    return <Skeleton className="h-48 w-full" />;
  }

  const figure = (key: string, value: number): string =>
    key === 'storage_bytes'
      ? readableSize(value, i18n.language)
      : formatNumber(value, i18n.language);
  const over = data.limits.filter((limit) => limit.over);

  return (
    <div className="flex flex-col gap-6">
      <section className="border-border bg-surface-raised flex flex-col gap-2 rounded-2xl border p-5">
        <p className="ds-overline text-content-tertiary">{t('plan.current')}</p>
        <h2 className="text-content text-xl font-semibold">{data.plan.name}</h2>
        <p className="text-content-secondary text-sm">{t('plan.managedBy')}</p>
      </section>

      {over.length > 0 ? (
        <Alert tone="warning">
          {t('plan.overNotice', {
            count: over.length,
            limits: over.map((limit) => t(`plan.limit.${limit.key}`)).join(', '),
          })}
        </Alert>
      ) : null}

      <section aria-labelledby="plan-limits" className="flex flex-col gap-3">
        <h3 id="plan-limits" className="text-content text-base font-semibold">
          {t('plan.limitsTitle')}
        </h3>
        <ul className="grid gap-3 md:grid-cols-2">
          {data.limits.map((limit) => {
            const name = t(`plan.limit.${limit.key}`);

            return (
              <li
                key={limit.key}
                className="border-border bg-surface-raised flex flex-col gap-2 rounded-2xl border p-4"
              >
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="text-content text-sm font-medium">{name}</span>
                  {limit.source === 'override' ? (
                    <Badge tone="info">{t('plan.overridden')}</Badge>
                  ) : null}
                </div>
                <p className="text-content-secondary text-sm tabular-nums">
                  {limit.used === null
                    ? t('plan.notMeasured')
                    : limit.allowed === null
                      ? t('plan.usedUnlimited', { used: figure(limit.key, limit.used) })
                      : t('plan.usedOf', {
                          used: figure(limit.key, limit.used),
                          allowed: figure(limit.key, limit.allowed),
                        })}
                </p>
                {limit.used !== null && limit.allowed !== null ? (
                  <ProgressBar
                    value={limit.used}
                    max={Math.max(limit.allowed, 1)}
                    tone={limit.over ? 'bad' : limit.used >= limit.allowed ? 'watch' : 'brand'}
                    label={name}
                  />
                ) : null}
                {limit.resets_at !== null ? (
                  <p className="text-content-tertiary text-xs">
                    {t('plan.resets', {
                      date: new Date(limit.resets_at).toLocaleDateString(localeTag(i18n.language)),
                    })}
                  </p>
                ) : null}
                {!limit.refuses ? (
                  <p className="text-content-tertiary text-xs">{t('plan.countedNotRefused')}</p>
                ) : null}
              </li>
            );
          })}
        </ul>
      </section>

      <section aria-labelledby="plan-features" className="flex flex-col gap-3">
        <h3 id="plan-features" className="text-content text-base font-semibold">
          {t('plan.featuresTitle')}
        </h3>
        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {data.features.map((feature) => (
            <li
              key={feature.key}
              className="border-border flex items-center justify-between gap-2 rounded-xl border px-3 py-2"
            >
              <span className="text-content text-sm">{t(`plan.feature.${feature.key}`)}</span>
              <Badge tone={feature.enabled ? 'success' : 'neutral'}>
                {feature.enabled ? t('plan.included') : t('plan.notIncluded')}
              </Badge>
            </li>
          ))}
        </ul>
      </section>

      {data.overrides.length > 0 ? (
        <section aria-labelledby="plan-overrides" className="flex flex-col gap-3">
          <h3 id="plan-overrides" className="text-content text-base font-semibold">
            {t('plan.overridesTitle')}
          </h3>
          <ul className="flex flex-col gap-2">
            {data.overrides.map((override) => (
              <li
                key={`${override.at}-${override.key}`}
                className="border-border rounded-xl border px-3 py-2 text-sm"
              >
                <span className="text-content font-medium">
                  {t(`plan.${override.kind === 'feature' ? 'feature' : 'limit'}.${override.key}`)}
                </span>{' '}
                <span className="text-content-secondary">
                  {t('plan.overrideLine', {
                    from: override.previous_value ?? '—',
                    to: override.value,
                    reason: override.reason,
                  })}
                </span>
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </div>
  );
}
