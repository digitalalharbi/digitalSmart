import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { providerName } from '@/shared/lib/providers';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Skeleton } from '@/shared/ui/skeleton';
import { useExecutionActionMutation, useLaunchesQuery, type LaunchExecution } from './api';

const TONE: Record<
  LaunchExecution['status'],
  'success' | 'warning' | 'danger' | 'info' | 'neutral'
> = {
  queued: 'info',
  creating: 'info',
  created: 'info',
  verified: 'success',
  readback_mismatch: 'warning',
  failed: 'danger',
  unknown: 'warning',
  skipped: 'neutral',
  blocked_external: 'neutral',
};

/**
 * Each account's part of a launch: where it stands, what the platform said, and the one thing a person can do about it — reconcile
 * an unknown outcome, or retry a proven failure. Nothing else is offered: an unknown outcome is never retried from here.
 */
export function LaunchExecutions({ planId, canExecute }: { planId: string; canExecute: boolean }) {
  const { t } = useTranslation();
  const launches = useLaunchesQuery(planId, true);
  const action = useExecutionActionMutation();

  if (launches.isPending) {
    return <Skeleton className="h-32 w-full" />;
  }

  const executions = launches.data ?? [];

  if (executions.length === 0) {
    return null;
  }

  const latest = executions[0]?.launch_id;

  return (
    <section aria-labelledby="launch-executions" className="flex flex-col gap-3">
      <h3 id="launch-executions" className="text-content text-base font-semibold">
        {t('launch.executionsTitle')}
      </h3>
      {action.isError ? (
        <Alert tone="danger">
          {action.error instanceof ApiError && action.error.message !== ''
            ? action.error.message
            : t('launch.actionFailed')}
        </Alert>
      ) : null}
      <ul className="flex flex-col gap-2">
        {executions
          .filter((execution) => execution.launch_id === latest)
          .map((execution) => (
            <li
              key={execution.id}
              className="border-border flex flex-col gap-2 rounded-xl border p-3 text-sm"
            >
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-content font-medium">{providerName(execution.provider)}</span>
                <span className="flex flex-wrap gap-1.5">
                  {execution.source === 'sandbox' ? (
                    <Badge tone="warning">{t('launch.sandbox')}</Badge>
                  ) : null}
                  <Badge tone={TONE[execution.status]}>
                    {t(`launch.status.${execution.status}`)}
                  </Badge>
                </span>
              </div>
              {execution.provider_ids.campaign !== null ? (
                <p className="text-content-secondary text-xs" dir="ltr">
                  {execution.provider_ids.campaign}
                </p>
              ) : null}
              {execution.activation_status !== null ? (
                <p className="text-content text-xs">
                  {t('activation.label')}: {t(`activation.status.${execution.activation_status}`)}
                  {execution.activation_message !== null
                    ? ` — ${execution.activation_message}`
                    : ''}
                </p>
              ) : null}
              {execution.message !== null ? (
                <p className="text-content-secondary">{execution.message}</p>
              ) : null}
              {canExecute && (execution.may_reconcile || execution.may_retry) ? (
                <div className="flex gap-2">
                  {execution.may_reconcile ? (
                    <Button
                      variant="secondary"
                      disabled={action.isPending}
                      onClick={() => action.mutate({ id: execution.id, action: 'reconcile' })}
                    >
                      {t('launch.reconcile')}
                    </Button>
                  ) : null}
                  {execution.may_retry ? (
                    <Button
                      variant="secondary"
                      disabled={action.isPending}
                      onClick={() => action.mutate({ id: execution.id, action: 'retry' })}
                    >
                      {t('launch.retry')}
                    </Button>
                  ) : null}
                </div>
              ) : null}
            </li>
          ))}
      </ul>
    </section>
  );
}
