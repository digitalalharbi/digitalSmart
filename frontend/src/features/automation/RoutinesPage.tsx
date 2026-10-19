import { useTranslation } from 'react-i18next';
import { CalendarClock } from 'lucide-react';
import { cn } from '@/shared/lib/cn';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Alert } from '@/shared/ui/alert';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { useAgentsStatus, type RoutineStatus } from './api';
import { when } from './ruleText';

const DAY = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as const;

/** Routines — the schedules the agents and the rules keep: when, which agent, last run and result, next run. */
export function RoutinesPage() {
  const { t, i18n } = useTranslation();
  const status = useAgentsStatus();

  const schedule = (r: RoutineStatus) =>
    r.schedule.every_minutes !== undefined
      ? t('automation.every', { count: r.schedule.every_minutes })
      : `${r.schedule.days ? r.schedule.days.map((d) => t(`automation.day.${DAY[d]!}`)).join(' · ') + ' ' : t('automation.daily') + ' '}${r.schedule.at ?? ''} ${t('automation.riyadh')}`;

  return (
    <div className="ds-page">
      <PageHeader title={t('automation.routinesTab')} />
      {status.isError ? <Alert tone="danger">{t('automation.failed')}</Alert> : null}
      {status.isPending ? (
        <Skeleton className="h-64 rounded-3xl" />
      ) : (
        <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {(status.data?.routines ?? []).map((r) => (
            <li
              key={r.routine}
              className="bg-surface flex flex-col gap-3 rounded-3xl p-5 shadow-xs"
            >
              <span className="flex items-center justify-between gap-2">
                <span className="text-content flex items-center gap-2 font-semibold">
                  <CalendarClock aria-hidden className="text-content-brand size-4" />
                  {t(`automation.routine.${r.routine}`)}
                </span>
                <span
                  className={cn(
                    'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                    r.last_status === 'failed'
                      ? 'bg-danger-surface text-danger'
                      : r.last_status === null
                        ? 'bg-surface-sunken text-content-tertiary'
                        : 'bg-success-surface text-success',
                  )}
                >
                  {r.last_status === null
                    ? t('automation.agentStatus.never')
                    : t(`automation.runStatus.${r.last_status}`, { defaultValue: r.last_status })}
                </span>
              </span>
              <span className="text-content-secondary text-sm" dir="auto">
                {schedule(r)}
              </span>
              <dl className="grid grid-cols-2 gap-2 text-xs">
                <div>
                  <dt className="text-content-tertiary">{t('automation.agentLabel')}</dt>
                  <dd className="text-content">{t(`automation.runner.${r.agent}`)}</dd>
                </div>
                <div>
                  <dt className="text-content-tertiary">{t('automation.lastRun')}</dt>
                  <dd className="text-content">
                    {r.last_run === null ? '—' : formatRelativeTime(r.last_run, i18n.language)}
                  </dd>
                </div>
                <div className="col-span-2">
                  <dt className="text-content-tertiary">{t('automation.nextRun')}</dt>
                  <dd className="text-content ds-numeric">{when(r.next_run, i18n.language)}</dd>
                </div>
              </dl>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
