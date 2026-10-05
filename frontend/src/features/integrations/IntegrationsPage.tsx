import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Skeleton } from '@/shared/ui/skeleton';
import { useProjectsQuery } from '@/features/projects';
import { AccountBindingPanel } from './AccountBindingPanel';
import { ProviderCard } from './ProviderCard';
import { useProvidersQuery } from './api';

export function IntegrationsPage() {
  const { t } = useTranslation();
  const { data: providers, isPending, isError, error } = useProvidersQuery();
  const { data: projectPage, isPending: loadingProjects } = useProjectsQuery({ per_page: 100 });

  const projects = useMemo(
    () =>
      (projectPage?.data ?? []).map((project) => ({
        id: project.id,
        name: project.name,
        clientName: project.client.name,
      })),
    [projectPage],
  );

  if (isError && error instanceof ApiError && error.status === 403) {
    return (
      <div className="flex flex-col gap-6">
        <h1 className="text-content text-2xl font-semibold">{t('nav.integrations')}</h1>
        <Alert tone="warning">{t('integrations.forbidden')}</Alert>
      </div>
    );
  }

  const blockedCount = (providers ?? []).filter((provider) => provider.blocked_external).length;

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-col gap-1">
        <h1 className="text-content text-2xl font-semibold">{t('nav.integrations')}</h1>
        <p className="text-content-secondary text-sm">{t('integrations.subtitle')}</p>
      </header>

      {/*
        Said once, plainly, at the top: nothing here is connected yet, and the
        reason is an external approval rather than unfinished work.
      */}
      {blockedCount > 0 ? (
        <Alert tone="info">
          <p className="font-medium">{t('integrations.awaitingAccessTitle')}</p>
          <p className="mt-1 text-sm">
            {t('integrations.awaitingAccessBody', { count: blockedCount })}
          </p>
        </Alert>
      ) : null}

      {isPending ? (
        <div
          className="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
          aria-busy="true"
          aria-label={t('integrations.loading')}
        >
          {Array.from({ length: 6 }, (_, index) => (
            <Skeleton key={index} className="h-56" />
          ))}
        </div>
      ) : null}

      {isError && !(error instanceof ApiError && error.status === 403) ? (
        <Alert tone="danger">
          {error instanceof ApiError ? error.message : t('integrations.loadFailed')}
        </Alert>
      ) : null}

      {providers ? (
        <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {providers.map((provider) => (
            <ProviderCard key={provider.provider} provider={provider} />
          ))}
        </section>
      ) : null}

      <section className="border-line-subtle border-t pt-8">
        <AccountBindingPanel projects={projects} isLoadingProjects={loadingProjects} />
      </section>
    </div>
  );
}
