import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useProjectsQuery } from '@/features/projects';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { EntityPicker } from '@/shared/ui/entity-picker';
import {
  useAccountQueueQuery,
  useAssignAccount,
  useTriageAccount,
  type AccountState,
  type QueueAccount,
} from './connections';

const STATES: AccountState[] = ['unassigned', 'bound', 'shared', 'ignored'];

/**
 * Discovered accounts, and what each one is for.
 *
 * Nothing is bound silently and nothing is discarded. An unassigned account is waiting for somebody to put
 * it in a project or say it is not one of ours; *ignored* is a decision with a name and a date on it, and
 * can be undone. Counts are over the whole population, so "eleven need assigning" does not change when
 * somebody scrolls.
 */
export function AccountQueuePanel() {
  const { t } = useTranslation();
  const [state, setState] = useState<AccountState>('unassigned');
  const { data, isPending, isError } = useAccountQueueQuery([state]);
  const triage = useTriageAccount();
  const assign = useAssignAccount();
  const [term, setTerm] = useState('');
  const projects = useProjectsQuery({ search: term, per_page: 8 });
  const error = triage.error ?? assign.error;

  return (
    <section aria-label={t('accountQueue.title')} className="flex flex-col gap-3">
      <div>
        <h2 className="text-content text-lg font-semibold">{t('accountQueue.title')}</h2>
        <p className="text-content-secondary text-sm">{t('accountQueue.subtitle')}</p>
      </div>

      <ChoiceGroup label={t('accountQueue.show')}>
        <div className="flex flex-wrap gap-2">
          {STATES.map((s) => (
            <Choice key={s} isSelected={state === s} onSelect={() => setState(s)}>
              {t(`accountQueue.state.${s}`)}
              {data === undefined ? null : (
                <span className="ms-1.5 tabular-nums">{data.counts[s]}</span>
              )}
            </Choice>
          ))}
        </div>
      </ChoiceGroup>

      {isError ? <Alert tone="danger">{t('accountQueue.loadFailed')}</Alert> : null}
      {error === null ? null : (
        <Alert tone="danger">
          {error instanceof ApiError ? error.message : t('accountQueue.actionFailed')}
        </Alert>
      )}

      {isPending ? null : (data?.data ?? []).length === 0 ? (
        <EmptyState
          title={t(`accountQueue.empty.${state}`)}
          description={t('accountQueue.emptyBody')}
        />
      ) : (
        <ul className="flex flex-col gap-2">
          {(data?.data ?? []).map((account) => (
            <li key={account.id}>
              <AccountRow
                account={account}
                onIgnore={(ignore) => triage.mutate({ id: account.id, ignore })}
                onAssign={(projectId) => assign.mutate({ projectId, accountId: account.id })}
                projectOptions={(projects.data?.data ?? []).map((p) => ({
                  id: p.id,
                  label: p.name,
                  context: p.client.name,
                }))}
                onSearch={setTerm}
                searching={projects.isFetching}
              />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function AccountRow({
  account,
  onIgnore,
  onAssign,
  projectOptions,
  onSearch,
  searching,
}: {
  account: QueueAccount;
  onIgnore: (ignore: boolean) => void;
  onAssign: (projectId: string) => void;
  projectOptions: Array<{ id: string; label: string; context: string }>;
  onSearch: (term: string) => void;
  searching: boolean;
}) {
  const { t } = useTranslation();

  return (
    <article
      aria-label={account.name}
      className="bg-surface border-line-subtle flex flex-wrap items-center justify-between gap-3 rounded-2xl border p-4 shadow-xs"
    >
      <div className="min-w-0">
        {/* The human label leads; the native id is secondary, for whoever has to match it to a platform. */}
        <p className="text-content truncate font-medium">{account.name}</p>
        <p className="text-content-tertiary truncate text-xs">
          {account.connection.label} · {account.external_id}
          {account.currency === null ? '' : ` · ${account.currency}`}
        </p>
        {account.projects.length === 0 ? null : (
          <p className="text-content-secondary mt-1 text-xs">
            {account.projects.map((p) => `${p.name} (${p.client_name})`).join(', ')}
          </p>
        )}
      </div>

      <div className="flex flex-wrap items-end gap-2">
        {account.owner_client === null ? null : (
          <Badge tone="info">
            {t('accountQueue.ownedBy', { name: account.owner_client.name })}
          </Badge>
        )}
        <Badge tone="neutral">{t('accountQueue.campaigns', { count: account.campaigns })}</Badge>

        {account.state === 'unassigned' ? (
          <>
            <EntityPicker
              label={t('accountQueue.assign')}
              clearLabel={t('campaignControl.clear')}
              value={null}
              onChange={(o) => {
                if (o !== null) {
                  onAssign(o.id);
                }
              }}
              onSearch={onSearch}
              options={projectOptions}
              isLoading={searching}
            />
            <Button size="sm" variant="ghost" onClick={() => onIgnore(true)}>
              {t('accountQueue.ignore')}
            </Button>
          </>
        ) : null}
        {account.state === 'ignored' ? (
          <Button size="sm" variant="secondary" onClick={() => onIgnore(false)}>
            {t('accountQueue.restore')}
          </Button>
        ) : null}
      </div>
    </article>
  );
}
