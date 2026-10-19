import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Plus, Zap } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useUiStore } from '@/shared/store/ui-store';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { useCreateRule, useRulesQuery } from './api';
import { RuleCard } from './RuleCard';
import { RuleBuilder } from './RuleBuilder';
import { COVERED, TEMPLATES, draftFrom } from './templates';

/**
 * Rules: tell the system what to watch for, and it puts what it finds on the Today list.
 *
 * Templates first, because most agencies want the same nine rules and should not build them from blank. One tap
 * turns a template on, scoped to whatever client or project the person is already working in (or the whole agency).
 * A rule can only raise an item for a person — it has no power over a campaign, so nothing here can move money.
 */
export function AutomationPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const rules = useRulesQuery();
  const create = useCreateRule();
  const context = useUiStore((state) => state.context);
  const mayWrite = can(PERMISSIONS.budgetsManage);

  const scope = {
    client_id: context.project === null ? (context.client?.id ?? null) : null,
    project_id: context.project?.id ?? null,
  };
  const scoped = context.project?.label ?? context.client?.label ?? null;
  const taken = new Set((rules.data ?? []).map((rule) => rule.name));
  const [building, setBuilding] = useState(false);

  return (
    <div className="ds-page">
      <PageHeader
        title={t('automation.title')}
        primaryAction={
          mayWrite ? (
            <Button onClick={() => setBuilding((open) => !open)}>
              <Plus />
              {t('automation.newRule')}
            </Button>
          ) : undefined
        }
      />

      {building ? <RuleBuilder scope={scope} onDone={() => setBuilding(false)} /> : null}

      {create.isError ? <Alert tone="danger">{t('automation.failed')}</Alert> : null}

      <section aria-label={t('automation.templates')} className="flex flex-col gap-3">
        <h2 className="text-content text-lg font-semibold">
          {t('automation.templates')}
          {scoped === null ? null : (
            <span className="text-content-tertiary ms-2 text-sm font-normal" dir="auto">
              {scoped}
            </span>
          )}
        </h2>
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {TEMPLATES.map((template) => {
            const name = t(`automation.template.${template.key}`);
            const on = taken.has(name);

            return (
              <li
                key={template.key}
                className="bg-surface flex flex-col gap-3 rounded-3xl p-5 shadow-xs"
              >
                <span className="bg-accent-subtle text-content-brand grid size-9 place-items-center rounded-full">
                  <Zap aria-hidden className="size-4" />
                </span>
                <p className="text-content font-semibold">{name}</p>
                <p className="text-content-tertiary text-xs">
                  {template.action === undefined || template.action === 'alert'
                    ? t('automation.toToday')
                    : t(`automation.action.${template.action}`, { pct: template.actionPct ?? 0 })}
                  {template.forDays !== undefined && template.forDays > 1
                    ? ` · ${t('automation.days', { count: template.forDays })}`
                    : ''}
                </p>
                <Button
                  className="mt-auto"
                  variant={on ? 'secondary' : 'primary'}
                  disabled={on || !mayWrite || create.isPending}
                  onClick={() => create.mutate(draftFrom(template, name, scope))}
                >
                  {on ? (
                    t('automation.on')
                  ) : (
                    <>
                      <Plus />
                      {t('automation.turnOn')}
                    </>
                  )}
                </Button>
              </li>
            );
          })}
          {COVERED.map((item) => (
            <li
              key={item.key}
              className="bg-surface-sunken text-content-tertiary flex flex-col gap-2 rounded-3xl p-5"
            >
              <p className="font-semibold">{t(`automation.template.${item.key}`)}</p>
              <p className="text-xs">{t(`automation.covered.${item.by}`)}</p>
            </li>
          ))}
        </ul>
      </section>

      <section aria-label={t('automation.yours')} className="flex flex-col gap-3">
        <h2 className="text-content text-lg font-semibold">{t('automation.yours')}</h2>
        {rules.isPending ? (
          <Skeleton className="h-48 rounded-3xl" />
        ) : (rules.data ?? []).length === 0 ? (
          <p className="text-content-tertiary text-sm">{t('automation.none')}</p>
        ) : (
          <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {(rules.data ?? []).map((rule) => (
              <li key={rule.id} className="flex">
                <div className="flex w-full">
                  <RuleCard rule={rule} />
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
