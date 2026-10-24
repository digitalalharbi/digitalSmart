import { useSearchParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { HealthPanel } from '@/features/overview';
import { Alert } from '@/shared/ui/alert';
import { PageHeader } from '@/shared/ui/page-header';
import { cn } from '@/shared/lib/cn';
import { AuditPanel } from './AuditPanel';
import { MembersPanel } from './MembersPanel';
import { OperationsPanel } from './OperationsPanel';
import { PlanPanel } from './PlanPanel';
import { RolesPanel } from './RolesPanel';
import { SyncIndicator } from './SyncIndicator';
import { SetupPanel } from './SetupPanel';

type Section = 'people' | 'roles' | 'plan' | 'setup' | 'audit' | 'health' | 'diagnostics';

/**
 * Administration, in the order an administrator thinks.
 *
 * People and what they may do; the setup that lives on other pages; the audit trail; and system health — which
 * is plain counts for anybody who may see it, with the technical detail (queues, error rates, failing runs)
 * kept in one named place, **Advanced diagnostics**, so that nothing technical appears anywhere else.
 */
export function AdministrationPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const [params, setParams] = useSearchParams();

  const canSeePeople = can(PERMISSIONS.usersView);
  const canSeeAudit = can(PERMISSIONS.auditView);
  const canSeeOperations = can(PERMISSIONS.workspacesView);
  const canSeePlan = can(PERMISSIONS.workspacesUpdate);

  if (!canSeePeople && !canSeeAudit && !canSeeOperations) {
    return (
      <div className="flex flex-col gap-6">
        <h1 className="text-content text-2xl font-semibold">{t('nav.administration')}</h1>
        <Alert tone="warning">{t('administration.forbidden')}</Alert>
      </div>
    );
  }

  const groups: Array<{
    label: string;
    items: Array<{ id: Section; label: string; visible: boolean }>;
  }> = [
    {
      label: t('administration.group.workspace'),
      items: [
        { id: 'people', label: t('administration.people'), visible: canSeePeople },
        { id: 'roles', label: t('administration.rolesTitle'), visible: canSeePeople },
        { id: 'plan', label: t('plan.title'), visible: canSeePlan },
        { id: 'setup', label: t('administration.setup'), visible: true },
      ],
    },
    {
      label: t('administration.group.oversight'),
      items: [
        { id: 'audit', label: t('administration.auditLog'), visible: canSeeAudit },
        { id: 'health', label: t('administration.health'), visible: canSeeOperations },
        { id: 'diagnostics', label: t('administration.diagnostics'), visible: canSeeOperations },
      ],
    },
  ];
  const visible = groups
    .map((group) => ({ ...group, items: group.items.filter((item) => item.visible) }))
    .filter((group) => group.items.length > 0);
  const all = visible.flatMap((group) => group.items);
  const requested = params.get('section');
  const active: Section = all.some((item) => item.id === requested)
    ? (requested as Section)
    : (all[0]?.id ?? 'setup');

  return (
    <div className="ds-page">
      <PageHeader title={t('nav.administration')} description={t('administration.subtitle')} />

      <div className="grid gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
        <nav aria-label={t('administration.sections')} className="flex flex-col gap-4">
          {visible.map((group) => (
            <div key={group.label} className="flex flex-col gap-1">
              <p className="ds-overline text-content-tertiary px-2">{group.label}</p>
              <ul className="flex gap-1 overflow-x-auto lg:flex-col">
                {group.items.map((item) => (
                  <li key={item.id} className="shrink-0">
                    <button
                      type="button"
                      aria-current={active === item.id ? 'page' : undefined}
                      onClick={() => setParams({ section: item.id }, { replace: true })}
                      className={cn(
                        'w-full rounded-lg px-3 py-2 text-start text-sm font-medium transition-colors duration-[var(--ds-duration-fast)]',
                        'focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none',
                        active === item.id
                          ? 'bg-accent-subtle text-content-brand'
                          : 'text-content-secondary hover:bg-surface-sunken hover:text-content',
                      )}
                    >
                      {item.label}
                    </button>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </nav>

        <div className="min-w-0">
          {active === 'people' ? <MembersPanel /> : null}
          {active === 'roles' ? <RolesPanel /> : null}
          {active === 'plan' ? <PlanPanel /> : null}
          {active === 'setup' ? <SetupPanel /> : null}
          {active === 'audit' ? <AuditPanel /> : null}
          {active === 'health' ? <OperationsPanel part="health" /> : null}
          {active === 'diagnostics' ? (
            <div className="flex flex-col gap-6">
              <SyncIndicator />
              <HealthPanel />
              <OperationsPanel part="diagnostics" />
            </div>
          ) : null}
        </div>
      </div>
    </div>
  );
}
