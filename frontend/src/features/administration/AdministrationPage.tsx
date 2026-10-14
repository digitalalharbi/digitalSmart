import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { Alert } from '@/shared/ui/alert';
import { PageHeader } from '@/shared/ui/page-header';
import { cn } from '@/shared/lib/cn';
import { AuditPanel } from './AuditPanel';
import { MembersPanel } from './MembersPanel';

type Tab = 'people' | 'audit';

export function AdministrationPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const [tab, setTab] = useState<Tab>('people');

  const canSeePeople = can(PERMISSIONS.usersView);
  const canSeeAudit = can(PERMISSIONS.auditView);

  if (!canSeePeople && !canSeeAudit) {
    return (
      <div className="flex flex-col gap-6">
        <h1 className="text-content text-2xl font-semibold">{t('nav.administration')}</h1>
        <Alert tone="warning">{t('administration.forbidden')}</Alert>
      </div>
    );
  }

  const tabs: { id: Tab; label: string; visible: boolean }[] = [
    { id: 'people', label: t('administration.people'), visible: canSeePeople },
    { id: 'audit', label: t('administration.auditLog'), visible: canSeeAudit },
  ];
  const visibleTabs = tabs.filter((candidate) => candidate.visible);
  const active = visibleTabs.some((candidate) => candidate.id === tab)
    ? tab
    : (visibleTabs[0]?.id ?? 'people');

  return (
    <div className="ds-page">
      <PageHeader title={t('nav.administration')} description={t('administration.subtitle')} />

      <div className="border-line-subtle flex gap-1 border-b" role="tablist">
        {visibleTabs.map((candidate) => (
          <button
            key={candidate.id}
            type="button"
            role="tab"
            aria-selected={active === candidate.id}
            onClick={() => setTab(candidate.id)}
            className={cn(
              '-mb-px border-b-2 px-3 py-2 text-sm font-medium transition-colors duration-[var(--ds-duration-fast)]',
              active === candidate.id
                ? 'border-accent text-content'
                : 'text-content-secondary hover:text-content border-transparent',
            )}
          >
            {candidate.label}
          </button>
        ))}
      </div>

      <div role="tabpanel">{active === 'people' ? <MembersPanel /> : <AuditPanel />}</div>
    </div>
  );
}
