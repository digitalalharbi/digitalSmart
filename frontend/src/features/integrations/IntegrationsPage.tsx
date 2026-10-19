import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Skeleton } from '@/shared/ui/skeleton';
import { LoadingRegion } from '@/shared/ui/loading-region';
import { InfoHint } from '@/shared/ui/info-hint';
import { PageHeader } from '@/shared/ui/page-header';
import { useProjectsQuery } from '@/features/projects';
import { AccountBindingPanel } from './AccountBindingPanel';
import { AccountQueuePanel } from './AccountQueuePanel';
import { ConnectionsPanel } from './ConnectionsPanel';
import { ProviderCard } from './ProviderCard';
import { ReadinessPanel } from './ReadinessPanel';
import type { ProviderRow } from './types';
import { useProvidersQuery } from './api';

/**
 * Providers by category, in the registry's own order.
 *
 * Insertion order, not alphabetical: the registry lists advertising first because that is
 * where most of the work happens, and an alphabetical page would open on commerce.
 */
function groupByCategory(providers: ProviderRow[]): Array<[string, ProviderRow[]]> {
  const groups = new Map<string, ProviderRow[]>();

  for (const provider of providers) {
    const existing = groups.get(provider.category);

    if (existing === undefined) {
      groups.set(provider.category, [provider]);
    } else {
      existing.push(provider);
    }
  }

  return [...groups.entries()];
}

export function IntegrationsPage() {
  const { t } = useTranslation();
  const { data: providers, isPending, isError, error } = useProvidersQuery();
  /* Searched, never listed: an agency has more projects than fit in a page, and the picker finds by name. */
  const [projectTerm, setProjectTerm] = useState('');
  const { data: projectPage, isFetching: loadingProjects } = useProjectsQuery({
    search: projectTerm,
    per_page: 8,
  });

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
    <div className="ds-page">
      <PageHeader title={t('nav.integrations')} description={t('integrations.subtitle')} />

      {/*
        The state of every platform at a glance: how many are connected, how many run on sandbox credentials, how many
        wait on a provider's approval. The reason they wait is behind the ⓘ — it is a fact about the provider, not
        something to re-read on every visit.
      */}
      {providers ? (
        <ul className="flex flex-wrap gap-3" aria-label={t('integrations.summary')}>
          {(
            [
              [
                'connected',
                providers.filter((p) => p.status === 'connected' && !p.is_sandbox).length,
                'var(--ds-success-500)',
              ],
              ['sandbox', providers.filter((p) => p.is_sandbox).length, 'var(--ds-accent)'],
              ['waiting', blockedCount, 'var(--ds-warning-500)'],
            ] as const
          ).map(([key, count, dot]) => (
            <li
              key={key}
              className="bg-surface flex items-center gap-3 rounded-2xl px-4 py-3 shadow-xs"
            >
              <span aria-hidden className="size-2.5 rounded-full" style={{ background: dot }} />
              <span className="ds-numeric text-content text-2xl font-semibold">{count}</span>
              <span className="text-content-secondary text-sm">
                {t(`integrations.state.${key}`)}
              </span>
              {key === 'waiting' && count > 0 ? (
                <InfoHint text={t('integrations.awaitingAccessBody', { count })} />
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}

      {isPending ? (
        <LoadingRegion
          label={t('integrations.loading')}
          className="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
          {Array.from({ length: 6 }, (_, index) => (
            <Skeleton key={index} className="h-56" />
          ))}
        </LoadingRegion>
      ) : null}

      {isError && !(error instanceof ApiError && error.status === 403) ? (
        <Alert tone="danger">
          {error instanceof ApiError ? error.message : t('integrations.loadFailed')}
        </Alert>
      ) : null}

      {/*
       * Grouped by what each provider *is*, because eleven cards in one grid is a list
       * to scan rather than a page to read — and because the categories are the thing
       * that must never be totalled together. The headings come from the registry, so
       * adding a provider puts it in the right place without touching this file.
       */}
      {providers
        ? groupByCategory(providers).map(([category, rows]) => (
            <section key={category} className="flex flex-col gap-3">
              <h2 className="text-content text-sm font-semibold">{rows[0]?.category_label}</h2>
              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {rows.map((provider) => (
                  <ProviderCard key={provider.provider} provider={provider} />
                ))}
              </div>
            </section>
          ))
        : null}

      {/*
        Four levels, kept as four: the catalogue above, then connections, then the accounts they discovered,
        then what each account is bound to. Merged into one page of cards they were one confusing list.

        Only once the catalogue has answered: it is the read that proves the reader may look at integrations at
        all. Mounted earlier, a person who may not would see two panels each announce a failure before the page
        told them why.
      */}
      {providers ? (
        <>
          <section className="border-line-subtle border-t pt-8">
            <ConnectionsPanel />
          </section>

          <section className="border-line-subtle border-t pt-8">
            <AccountQueuePanel />
          </section>

          <section className="border-line-subtle border-t pt-8">
            <ReadinessPanel />
          </section>

          <section className="border-line-subtle border-t pt-8">
            <AccountBindingPanel
              projects={projects}
              isLoadingProjects={loadingProjects}
              onSearchProjects={setProjectTerm}
            />
          </section>
        </>
      ) : null}
    </div>
  );
}
