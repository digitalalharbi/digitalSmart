import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { PERMISSIONS, useSession } from '@/features/auth';
import { formatMinor } from '@/shared/lib/format';
import { providerName } from '@/shared/lib/providers';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { buttonVariants } from '@/shared/ui/button.variants';
import { FilterSelect } from '@/shared/ui/filter-select';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { usePlansQuery, type PlanStatus } from './api';

const STATUSES: PlanStatus[] = ['draft', 'ready_for_review', 'approved', 'cancelled'];

/** Every campaign plan the reader may see, newest first, with where each stands. */
export function CampaignPlansPage() {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const [status, setStatus] = useState<string | null>(null);
  const plans = usePlansQuery(status ?? '');

  return (
    <div className="ds-page">
      <PageHeader
        title={t('campaignPlan.plansTitle')}
        description={t('campaignPlan.plansSubtitle')}
        actions={
          can(PERMISSIONS.campaignBlueprintsCreate) ? (
            <Link to="/campaigns/new" className={buttonVariants({ variant: 'primary' })}>
              {t('campaignPlan.new')}
            </Link>
          ) : null
        }
      />
      <div className="max-w-xs">
        <FilterSelect
          label={t('campaignPlan.statusFilter')}
          value={status}
          onChange={setStatus}
          any={t('campaignPlan.anyStatus')}
          options={STATUSES.map((value) => ({ value, label: t(`campaignPlan.status.${value}`) }))}
        />
      </div>
      {plans.isError ? <Alert tone="danger">{t('campaignPlan.loadFailed')}</Alert> : null}
      {plans.isPending ? <Skeleton className="h-40 w-full" /> : null}
      {plans.data?.data.length === 0 ? (
        <p className="text-content-secondary text-sm">{t('campaignPlan.none')}</p>
      ) : null}
      <ul className="grid gap-3 lg:grid-cols-2">
        {(plans.data?.data ?? []).map((plan) => (
          <li key={plan.id}>
            <Link
              to={`/campaigns/plans/${plan.id}`}
              className="border-border bg-surface-raised hover:bg-surface-hover focus-visible:ring-accent/40 flex flex-col gap-2 rounded-2xl border p-4 focus-visible:ring-2 focus-visible:outline-none"
            >
              <span className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-content font-semibold">{plan.name}</span>
                <Badge
                  tone={
                    plan.status === 'cancelled'
                      ? 'neutral'
                      : plan.status === 'ready_for_review'
                        ? 'info'
                        : 'accent'
                  }
                >
                  {t(`campaignPlan.status.${plan.status}`)}
                </Badge>
              </span>
              <span className="text-content-secondary text-sm">
                {plan.client.name} — {plan.project.name}
              </span>
              <span className="text-content-tertiary text-xs">
                {t(`campaignPlan.objectives.${plan.objective}`)} ·{' '}
                {formatMinor(plan.budget_minor, plan.currency, i18n.language)} ·{' '}
                {plan.targets.map((target) => providerName(target.provider)).join(', ') ||
                  t('campaignPlan.noAccountsShort')}
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}
