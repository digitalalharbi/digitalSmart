import { useTranslation } from 'react-i18next';
import { CircleAlert, CircleCheck, CircleSlash, CircleX, KeyRound, Lock } from 'lucide-react';
import { cn } from '@/shared/lib/cn';
import { formatMinor, localeTag } from '@/shared/lib/format';
import { providerName } from '@/shared/lib/providers';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Skeleton } from '@/shared/ui/skeleton';
import { useLaunchPlanQuery, type PreflightCheck, type PreflightState } from './api';

const ICON = {
  ready: CircleCheck,
  warning: CircleAlert,
  blocked: CircleX,
  awaiting_credentials: KeyRound,
  blocked_external: Lock,
  unsupported: CircleSlash,
} as const;

const TONE: Record<PreflightState, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  ready: 'success',
  warning: 'warning',
  blocked: 'danger',
  awaiting_credentials: 'info',
  blocked_external: 'neutral',
  unsupported: 'neutral',
};

function Checks({ checks }: { checks: PreflightCheck[] }) {
  const { t } = useTranslation();

  return (
    <ul className="flex flex-col gap-2">
      {checks.map((check) => {
        const Icon = ICON[check.state];

        return (
          <li key={check.key} className="flex items-start gap-2 text-sm">
            <Icon
              aria-hidden
              className={cn(
                'mt-0.5 size-4 shrink-0',
                check.state === 'ready'
                  ? 'text-success'
                  : check.state === 'warning'
                    ? 'text-warning'
                    : check.state === 'blocked'
                      ? 'text-danger'
                      : 'text-content-tertiary',
              )}
            />
            <span className="min-w-0">
              <span className="sr-only">{t(`launchPlan.state.${check.state}`)}: </span>
              <span className="text-content">{check.message}</span>
              {check.fix !== null ? (
                <span className="text-content-secondary block text-xs">{check.fix}</span>
              ) : null}
            </span>
          </li>
        );
      })}
    </ul>
  );
}

/**
 * The launch plan: what would be written to each ad account, and every check between the plan and writing it, each with what
 * fixes it. Read-only; nothing here creates or validates anything on a platform.
 */
export function LaunchPlanView({ planId, version }: { planId: string; version: number }) {
  const { t, i18n } = useTranslation();
  const query = useLaunchPlanQuery(planId, version, true);
  const date = (value: string | null) =>
    value === null
      ? t('campaignPlan.noEnd')
      : new Date(value).toLocaleDateString(localeTag(i18n.language));

  if (query.isPending) {
    return <Skeleton className="h-48 w-full" />;
  }

  if (query.isError) {
    return <Alert tone="danger">{t('launchPlan.failed')}</Alert>;
  }

  const data = query.data;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={TONE[data.state]}>{t(`launchPlan.state.${data.state}`)}</Badge>
        <span className="text-content-secondary text-sm">
          {data.can_create ? t('launchPlan.canCreate') : t('launchPlan.cannotCreate')}
        </span>
      </div>
      <Alert tone="info">{t('campaignPlan.launchHint')}</Alert>

      {data.totals.length > 0 ? (
        <p className="text-content text-sm">
          {t('launchPlan.totals')}{' '}
          {data.totals
            .map((total) => formatMinor(total.budget_minor, total.currency, i18n.language))
            .join(' · ')}
        </p>
      ) : null}

      <section aria-labelledby="launch-checks" className="flex flex-col gap-2">
        <h3 id="launch-checks" className="text-content text-base font-semibold">
          {t('launchPlan.planChecks')}
        </h3>
        <Checks checks={data.checks} />
      </section>

      {data.providers.map(({ plan, checks, state }) => (
        <article
          key={plan.external_account_id}
          aria-label={`${providerName(plan.provider)} — ${plan.account_name}`}
          className="border-border flex flex-col gap-3 rounded-2xl border p-4"
        >
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h3 className="text-content text-base font-semibold">
              {providerName(plan.provider)}
              <span className="text-content-secondary ms-2 text-sm font-normal">
                {plan.account_name}
              </span>
            </h3>
            <Badge tone={TONE[state]}>{t(`launchPlan.state.${state}`)}</Badge>
          </div>
          <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-3">
            {(
              [
                [
                  'writes',
                  t('launchPlan.writesValue', {
                    campaigns: plan.campaigns,
                    adGroups: plan.ad_groups,
                    ads: plan.ads,
                  }),
                ],
                [
                  'budget',
                  `${formatMinor(plan.budget_minor, plan.account_currency ?? plan.currency, i18n.language)} · ${t(`campaignPlan.${plan.budget_type}`)}`,
                ],
                ['schedule', `${date(plan.starts_at)} → ${date(plan.ends_at)}`],
                ['objective', plan.objective ?? t('campaignPlan.noObjective')],
                ['conversion', plan.conversion ?? '—'],
                ['destination', plan.destination ?? '—'],
              ] as const
            ).map(([key, value]) => (
              <div key={key} className="flex min-w-0 flex-col">
                <dt className="text-content-tertiary">{t(`launchPlan.${key}`)}</dt>
                <dd className="text-content break-words">{value}</dd>
              </div>
            ))}
            <div className="flex min-w-0 flex-col sm:col-span-2 lg:col-span-3">
              <dt className="text-content-tertiary">{t('launchPlan.initialState')}</dt>
              <dd className="text-content break-words" dir="ltr">
                {plan.initial_state}
              </dd>
            </div>
          </dl>
          {plan.delivers_by_default ? (
            <Alert tone="warning">{t('campaignPlan.deliversByDefault')}</Alert>
          ) : null}
          <Checks checks={checks} />
        </article>
      ))}
    </div>
  );
}
