import { useTranslation } from 'react-i18next';
import { ArrowRight, Pencil, Trash2, Zap } from 'lucide-react';
import { cn } from '@/shared/lib/cn';
import { formatNumber } from '@/shared/lib/format';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { providerName } from '@/shared/lib/providers';
import { useUiStore } from '@/shared/store/ui-store';
import { Button } from '@/shared/ui/button';
import { actionText, ruleConditionParts } from './ruleText';
import type { ExecutionResult, Rule, RuleMode } from './types';

const MODE_TONE: Record<RuleMode, string> = {
  monitor: 'bg-surface-sunken text-content-secondary',
  recommend: 'bg-accent-subtle text-content-brand',
  auto: 'bg-warning-surface text-warning',
};

/** The counters a rule's last run leaves, in the order a person cares: what it did, what waits, what went wrong. */
const COUNTERS: Array<{ key: ExecutionResult; dot: string }> = [
  { key: 'completed', dot: 'var(--ds-success-500)' },
  { key: 'waiting_approval', dot: 'var(--ds-accent)' },
  { key: 'failed', dot: 'var(--ds-danger-500)' },
  { key: 'unsupported', dot: 'var(--ds-warning-500)' },
  { key: 'conflict', dot: 'var(--ds-warning-500)' },
  { key: 'cooldown', dot: 'var(--ds-neutral-400)' },
];

/**
 * One rule as a flow: **when** these figures → **then** this change, over this scope — with the last run's counters
 * underneath. No sentence to read: the trigger is chips, the action is a chip, the result is numbers.
 */
export function RuleCard({
  rule,
  picked,
  mayWrite,
  busy,
  onPick,
  onToggle,
  onEdit,
  onRemove,
}: {
  rule: Rule;
  picked: boolean;
  mayWrite: boolean;
  busy: boolean;
  onPick: () => void;
  onToggle: () => void;
  onEdit: () => void;
  onRemove: () => void;
}) {
  const { t, i18n } = useTranslation();
  const locale = useUiStore((state) => state.locale);
  const last = rule.last_result;
  const scope = rule.project?.name ?? rule.client?.name ?? t('automation.everywhere');
  const failed = (last?.failed ?? 0) > 0;

  return (
    <article
      aria-label={rule.name}
      className={cn(
        'bg-surface flex min-w-0 flex-col gap-4 rounded-3xl p-5 shadow-xs',
        !rule.is_active && 'opacity-70',
      )}
    >
      <header className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          {mayWrite ? (
            <input
              type="checkbox"
              aria-label={t('automation.bulk.select', { name: rule.name })}
              className="accent-action size-4 shrink-0"
              checked={picked}
              onChange={onPick}
            />
          ) : null}
          <button
            type="button"
            role="switch"
            aria-checked={rule.is_active}
            aria-label={t('automation.toggle', { name: rule.name })}
            disabled={!mayWrite || busy}
            onClick={onToggle}
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
          <span className="text-content truncate font-semibold" dir="auto">
            {rule.name}
          </span>
        </div>
        <div className="flex shrink-0 items-center gap-1.5">
          <span className="bg-surface-sunken text-content-secondary rounded-full px-2.5 py-1 text-xs font-semibold">
            {t(`automation.level.${rule.object_level}`)}
          </span>
          <span
            className={cn('rounded-full px-2.5 py-1 text-xs font-semibold', MODE_TONE[rule.mode])}
          >
            {t(`automation.mode.${rule.mode}`)}
          </span>
          {mayWrite ? (
            <>
              <Button
                variant="ghost"
                size="sm"
                aria-label={t('automation.edit', { name: rule.name })}
                onClick={onEdit}
              >
                <Pencil />
              </Button>
              <Button
                variant="ghost"
                size="sm"
                aria-label={t('automation.remove', { name: rule.name })}
                disabled={busy}
                onClick={onRemove}
              >
                <Trash2 />
              </Button>
            </>
          ) : null}
        </div>
      </header>

      {/* when → then → where. The arrows mirror in right-to-left, so the flow reads the way the language does. */}
      <div className="flex flex-wrap items-stretch gap-2">
        <div className="bg-surface-sunken flex min-w-0 flex-col gap-1.5 rounded-2xl p-3">
          <span className="text-content-tertiary ds-overline">{t('automation.when')}</span>
          <span className="flex flex-wrap items-center gap-1.5">
            {ruleConditionParts(t, rule).map((part, i) => (
              <span key={i} className="flex items-center gap-1.5">
                {i === 0 ? null : (
                  <span className="text-content-tertiary text-xs font-semibold">
                    {t(`automation.logic.${rule.logic}`)}
                  </span>
                )}
                <span className="bg-surface text-content rounded-full px-2.5 py-1 text-xs font-medium">
                  {part.name}
                  {part.comparison === null ? null : (
                    <>
                      {' '}
                      <bdi dir="ltr" className="ds-numeric font-semibold">
                        {part.comparison}
                      </bdi>
                    </>
                  )}
                </span>
              </span>
            ))}
          </span>
        </div>
        <ArrowRight
          aria-hidden
          className="text-content-tertiary size-4 self-center rtl:-scale-x-100"
        />
        <div className="bg-accent-subtle flex min-w-0 flex-col gap-1.5 rounded-2xl p-3">
          <span className="text-content-brand ds-overline">{t('automation.then')}</span>
          <span className="text-content-brand flex items-center gap-1.5 text-sm font-semibold">
            <Zap aria-hidden className="size-4" />
            <bdi>
              {rule.mode === 'monitor'
                ? actionText(t, 'notify', null)
                : actionText(t, rule.action, rule.action_pct)}
            </bdi>
          </span>
        </div>
        <div className="flex min-w-0 flex-1 flex-wrap content-center items-center justify-end gap-1.5">
          <span
            className="bg-surface-sunken text-content-secondary max-w-full truncate rounded-full px-2.5 py-1 text-xs"
            dir="auto"
          >
            {scope}
          </span>
          {rule.providers.map((p) => (
            <span
              key={p}
              className="bg-surface-sunken text-content-secondary rounded-full px-2.5 py-1 text-xs"
            >
              {providerName(p)}
            </span>
          ))}
        </div>
      </div>

      <footer className="border-line-subtle flex flex-wrap items-center gap-x-4 gap-y-2 border-t pt-3">
        {last === null ? (
          <span className="text-content-tertiary text-xs">{t('automation.notRunYet')}</span>
        ) : (
          <ul
            className="flex flex-wrap items-center gap-x-4 gap-y-1"
            aria-label={t('automation.col.lastResult')}
          >
            <li className="flex items-baseline gap-1.5">
              <span className="ds-numeric text-content text-lg font-semibold">
                {formatNumber(last.matched ?? 0, locale)}
              </span>
              <span className="text-content-tertiary text-xs">{t('automation.dry.matched')}</span>
            </li>
            {COUNTERS.filter(({ key }) => (last[key] ?? 0) > 0).map(({ key, dot }) => (
              <li key={key} className="flex items-center gap-1.5">
                <span aria-hidden className="size-2 rounded-full" style={{ background: dot }} />
                <span className="ds-numeric text-content text-sm font-semibold">
                  {formatNumber(last[key] ?? 0, locale)}
                </span>
                <span
                  className={cn(
                    'text-xs',
                    failed && key === 'failed' ? 'text-danger' : 'text-content-tertiary',
                  )}
                >
                  {t(`automation.result.${key}`)}
                </span>
              </li>
            ))}
          </ul>
        )}
        <span className="text-content-tertiary ms-auto text-xs">
          {rule.last_run_at === null ? '' : formatRelativeTime(rule.last_run_at, i18n.language)}
        </span>
      </footer>
    </article>
  );
}
