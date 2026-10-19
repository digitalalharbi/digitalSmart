import { useTranslation } from 'react-i18next';
import { CalendarRange } from 'lucide-react';
import { cn } from '@/shared/lib/cn';
import { useUiStore, type PeriodCompare, type PeriodDays } from '@/shared/store/ui-store';

const DAYS: PeriodDays[] = [7, 30, 90];
const COMPARE: PeriodCompare[] = ['previous_period', 'previous_year', 'none'];

/**
 * The one period control. Every analytical page shows this same control over the same shared choice, so a month
 * picked on Overview is the month on Campaigns, Performance and Leads — and the page always says which it is using.
 */
export function PeriodControl({
  withCompare = true,
  className,
}: {
  withCompare?: boolean;
  className?: string;
}) {
  const { t } = useTranslation();
  const period = useUiStore((state) => state.period);
  const setPeriod = useUiStore((state) => state.setPeriod);

  return (
    <div
      className={cn('flex flex-wrap items-center gap-2', className)}
      role="group"
      aria-label={t('period.label')}
    >
      <CalendarRange aria-hidden className="text-content-tertiary size-4" />
      <div className="bg-surface flex rounded-full p-1 shadow-xs">
        {DAYS.map((days) => (
          <button
            key={days}
            type="button"
            aria-pressed={period.days === days}
            onClick={() => setPeriod({ ...period, days })}
            className={cn(
              'rounded-full px-3 py-1.5 text-xs font-semibold',
              period.days === days
                ? 'bg-action text-action-contrast'
                : 'text-content-secondary hover:text-content',
            )}
          >
            {t('period.days', { count: days })}
          </button>
        ))}
      </div>
      {withCompare ? (
        <select
          aria-label={t('period.compare')}
          value={period.compare}
          onChange={(e) => setPeriod({ ...period, compare: e.target.value as PeriodCompare })}
          className="bg-surface text-content-secondary h-9 rounded-full px-3 text-xs font-medium shadow-xs"
        >
          {COMPARE.map((mode) => (
            <option key={mode} value={mode}>
              {t(`period.vs.${mode}`)}
            </option>
          ))}
        </select>
      ) : null}
    </div>
  );
}
