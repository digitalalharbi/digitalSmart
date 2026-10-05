import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link2, PauseCircle, PlayCircle, Unlink } from 'lucide-react';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { PERMISSIONS, useSession } from '@/features/auth';
import {
  useBindAccountMutation,
  useExternalAccountsQuery,
  useProjectBindingsQuery,
  useSetBindingSyncMutation,
  useUnbindAccountMutation,
} from './api';
import { ProviderLogo } from './ProviderLogo';

type AccountBindingPanelProps = {
  projects: { id: string; name: string; clientName: string }[];
  isLoadingProjects: boolean;
};

/**
 * Which external ad accounts a project may read.
 *
 * This is the isolation boundary in interface form: discovering an account
 * makes it available, and only an explicit grant here lets a project see it.
 * The copy says so, because someone granting access should understand what
 * they are doing.
 */
export function AccountBindingPanel({ projects, isLoadingProjects }: AccountBindingPanelProps) {
  const { t } = useTranslation();
  const { can } = useSession();
  const [projectId, setProjectId] = useState<string>('');

  const { data: accounts, isPending: loadingAccounts } = useExternalAccountsQuery();
  const { data: bindings, isPending: loadingBindings } = useProjectBindingsQuery(
    projectId || undefined,
  );

  const bind = useBindAccountMutation();
  const unbind = useUnbindAccountMutation();
  const setSync = useSetBindingSyncMutation();

  const canManage = can(PERMISSIONS.workspacesUpdate);
  const boundIds = new Set((bindings ?? []).map((binding) => binding.external_account.id));
  const error = [bind.error, unbind.error, setSync.error].find(
    (candidate): candidate is ApiError => candidate instanceof ApiError,
  );

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h2 className="text-content text-base font-semibold">{t('integrations.bindingsTitle')}</h2>
        <p className="text-content-secondary text-sm">{t('integrations.bindingsDescription')}</p>
      </div>

      {error ? <Alert tone="danger">{error.message}</Alert> : null}

      <label className="flex max-w-sm flex-col gap-1.5">
        <span className="text-content text-sm font-medium">{t('integrations.chooseProject')}</span>
        <select
          value={projectId}
          disabled={isLoadingProjects}
          onChange={(event) => setProjectId(event.target.value)}
          className="border-line bg-surface text-content h-9 w-full rounded-md border px-3 text-sm"
        >
          <option value="">{t('integrations.selectProject')}</option>
          {projects.map((project) => (
            <option key={project.id} value={project.id}>
              {project.clientName} — {project.name}
            </option>
          ))}
        </select>
      </label>

      {projectId === '' ? (
        <EmptyState
          title={t('integrations.selectProjectTitle')}
          description={t('integrations.selectProjectHint')}
        />
      ) : null}

      {projectId !== '' && (loadingAccounts || loadingBindings) ? (
        <div
          className="flex flex-col gap-2"
          aria-busy="true"
          aria-label={t('integrations.loading')}
        >
          {Array.from({ length: 3 }, (_, index) => (
            <Skeleton key={index} className="h-14" />
          ))}
        </div>
      ) : null}

      {projectId !== '' && accounts && accounts.length === 0 ? (
        <EmptyState
          title={t('integrations.noAccounts')}
          description={t('integrations.noAccountsHint')}
        />
      ) : null}

      {projectId !== '' && accounts && accounts.length > 0 ? (
        <ul className="flex flex-col gap-2" aria-label={t('integrations.accountsList')}>
          {accounts.map((account) => {
            const isBound = boundIds.has(account.id);
            const binding = bindings?.find(
              (candidate) => candidate.external_account.id === account.id,
            );

            return (
              <li
                key={account.id}
                className="border-line-subtle bg-surface flex flex-wrap items-center gap-3 rounded-lg border px-4 py-3"
              >
                <ProviderLogo provider={account.provider} className="size-8" />

                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-content text-sm font-medium">{account.name}</span>
                    {isBound ? <Badge tone="success">{t('integrations.linked')}</Badge> : null}
                    {!account.is_bindable ? (
                      <Badge tone="danger">
                        {t(`integrations.accountStatus.${account.status}`)}
                      </Badge>
                    ) : null}
                  </div>
                  <p className="text-content-tertiary ds-numeric text-xs">
                    {account.provider_label} · {account.external_id}
                    {account.currency ? ` · ${account.currency}` : ''}
                    {' · '}
                    {t('integrations.usedBy', { count: account.binding_count })}
                  </p>
                </div>

                {canManage ? (
                  <div className="flex gap-1.5">
                    {isBound && binding ? (
                      <>
                        <Button
                          variant="ghost"
                          size="sm"
                          disabled={setSync.isPending}
                          onClick={() =>
                            setSync.mutate({
                              projectId,
                              accountId: account.id,
                              enabled: !binding.sync_enabled,
                            })
                          }
                        >
                          {binding.sync_enabled ? <PauseCircle /> : <PlayCircle />}
                          {binding.sync_enabled
                            ? t('integrations.pauseSync')
                            : t('integrations.resumeSync')}
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="text-danger"
                          disabled={unbind.isPending}
                          onClick={() => unbind.mutate({ projectId, accountId: account.id })}
                        >
                          <Unlink />
                          {t('integrations.revoke')}
                        </Button>
                      </>
                    ) : (
                      <Button
                        variant="secondary"
                        size="sm"
                        disabled={!account.is_bindable || bind.isPending}
                        onClick={() => bind.mutate({ projectId, accountId: account.id })}
                      >
                        <Link2 />
                        {t('integrations.grant')}
                      </Button>
                    )}
                  </div>
                ) : null}
              </li>
            );
          })}
        </ul>
      ) : null}
    </div>
  );
}
