import { useTranslation } from 'react-i18next';
import { Bell, Trash2, Zap } from 'lucide-react';
import { cn } from '@/shared/lib/cn';
import { Button } from '@/shared/ui/button';
import { useDeleteRule, useUpdateRule } from './api';
import type { Rule } from './types';

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

  const limit =
    rule.threshold === null
      ? null
      : rule.metric === 'budget_used'
        ? `${String(rule.threshold)}%`
        : rule.metric === 'roas'
          ? `${String(rule.threshold)}×`
          : String(rule.threshold);

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
              draft: {
                name: rule.name,
                metric: rule.metric,
                threshold: rule.threshold,
                window_days: rule.window_days,
                severity: rule.severity,
                client_id: rule.client?.id ?? null,
                project_id: rule.project?.id ?? null,
                is_active: !rule.is_active,
              },
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
        <div className="bg-surface-sunken flex items-center gap-3 rounded-2xl p-3">
          <span className="text-content-tertiary text-xs font-semibold">
            {t('automation.then')}
          </span>
          <span className="flex items-center gap-2 text-sm font-medium">
            <Bell aria-hidden className="size-4" />
            {t('automation.toToday')}
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

      <footer className="mt-auto flex justify-end">
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

export function RuleIcon() {
  return <Zap aria-hidden className="size-4" />;
}
