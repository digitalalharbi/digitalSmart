import { useTranslation } from 'react-i18next';
import { Bell, Trash2, Zap } from 'lucide-react';
import { cn } from '@/shared/lib/cn';
import { Button } from '@/shared/ui/button';
import { useDeleteRule, useUpdateRule } from './api';
import { draftOf, type Rule, type RuleCondition } from './types';

const SEVERITY_TONE = {
  critical: 'bg-danger-surface text-danger',
  needs_attention: 'bg-warning-surface text-warning',
  watch: 'bg-surface-sunken text-content-secondary',
} as const;

/** "WHEN cost per result is above 100" → "THEN add it to Today". Two chips and an arrow; no code-like syntax. */
export function RuleCard({ rule }: { rule: Rule }) {
  const { t } = useTranslation();
  const update = useUpdateRule();
  const remove = useDeleteRule();

  const limit = limitOf(rule);

  const scope = rule.project?.name ?? rule.client?.name ?? t('automation.everywhere');

  return (
    <article
      aria-label={rule.name}
      className={cn(
        'bg-surface flex flex-col gap-4 rounded-3xl p-5 shadow-xs',
        !rule.is_active && 'opacity-60',
      )}
    >
      <header className="flex items-start justify-between gap-3">
        <p className="text-content-tertiary truncate text-xs" dir="auto">
          {scope}
        </p>
        <button
          type="button"
          role="switch"
          aria-checked={rule.is_active}
          aria-label={t('automation.toggle', { name: rule.name })}
          disabled={update.isPending}
          onClick={() =>
            update.mutate({
              id: rule.id,
              draft: { ...draftOf(rule), is_active: !rule.is_active },
            })
          }
          className={cn(
            'relative h-6 w-11 shrink-0 rounded-full transition-colors',
            rule.is_active ? 'bg-accent-strong' : 'bg-surface-active',
          )}
        >
          <span
            aria-hidden
            className={cn(
              'absolute top-0.5 size-5 rounded-full bg-white shadow-xs transition-all',
              rule.is_active ? 'start-[1.375rem]' : 'start-0.5',
            )}
          />
        </button>
      </header>

      <h3 className="text-content text-base font-semibold" dir="auto">
        {rule.name}
      </h3>

      <div className="flex flex-col gap-2">
        <div className="bg-surface-sunken flex items-center gap-3 rounded-2xl p-3">
          <span className="text-content-tertiary text-xs font-semibold">
            {t('automation.when')}
          </span>
          <span className="text-content text-sm font-medium">
            {rule.metric_label}
            {limit === null ? null : (
              <>
                {' '}
                <span className="text-content-secondary font-normal">
                  {t(`automation.${rule.operator}`)}
                </span>{' '}
                <span className="ds-numeric font-semibold">{limit}</span>
              </>
            )}
          </span>
        </div>
        {rule.conditions.map((condition, index) => (
          <div key={index} className="bg-surface-sunken flex items-center gap-3 rounded-2xl p-3">
            <span className="text-content-tertiary text-xs font-semibold">
              {t('automation.and')}
            </span>
            <span className="text-content text-sm font-medium">
              {condition.metric_label}
              {condition.threshold === null ? null : (
                <>
                  {' '}
                  <span className="ds-numeric font-semibold">{limitOf(condition)}</span>
                </>
              )}
            </span>
          </div>
        ))}
        {rule.for_days > 1 ? (
          <div className="bg-surface-sunken flex items-center gap-3 rounded-2xl p-3">
            <span className="text-content-tertiary text-xs font-semibold">
              {t('automation.for')}
            </span>
            <span className="text-content text-sm font-medium">
              {t('automation.days', { count: rule.for_days })}
            </span>
          </div>
        ) : null}
        <div className="bg-surface-sunken flex items-center gap-3 rounded-2xl p-3">
          <span className="text-content-tertiary text-xs font-semibold">
            {t('automation.then')}
          </span>
          <span className="flex flex-wrap items-center gap-2 text-sm font-medium">
            <Bell aria-hidden className="size-4" />
            {rule.action === 'alert'
              ? t('automation.toToday')
              : t(`automation.action.${rule.action}`, { pct: rule.action_pct ?? 0 })}
            <span
              className={cn(
                'rounded-full px-2 py-0.5 text-xs font-semibold',
                SEVERITY_TONE[rule.severity],
              )}
            >
              {t(`automation.severity.${rule.severity}`)}
            </span>
          </span>
        </div>
      </div>

      <footer className="mt-auto flex items-center justify-between gap-2">
        <span className="text-content-tertiary text-xs">
          {t('automation.trust.label', { level: rule.trust_level })} ·{' '}
          {t(`automation.trust.${rule.trust_level}`)}
        </span>
        <Button
          variant="ghost"
          size="sm"
          disabled={remove.isPending}
          onClick={() => remove.mutate(rule.id)}
          aria-label={t('automation.remove', { name: rule.name })}
        >
          <Trash2 />
        </Button>
      </footer>
    </article>
  );
}

/** The limit as a person says it: 90%, 2×, 3 days, 100. */
function limitOf(rule: {
  metric: Rule['metric'];
  threshold: RuleCondition['threshold'];
}): string | null {
  if (rule.threshold === null) return null;
  if (rule.metric === 'budget_used') return `${String(rule.threshold)}%`;
  if (rule.metric === 'roas') return `${String(rule.threshold)}×`;

  return String(rule.threshold);
}

export function RuleIcon() {
  return <Zap aria-hidden className="size-4" />;
}
