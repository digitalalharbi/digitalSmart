import * as Dialog from '@radix-ui/react-dialog';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useClientsQuery } from '@/features/clients';
import { ApiError } from '@/shared/api/client';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { useProvidersQuery } from './api';
import { credentialKeysFor } from './credentialKeys';
import {
  useConnectClientMutation,
  useConnectionAction,
  useConnectionsQuery,
  type Connection,
} from './connections';
import { returnFocus } from '@/shared/ui/return-focus';

const TONE = {
  healthy: 'success',
  needs_reconnect: 'danger',
  sync_issue: 'warning',
  awaiting_access: 'neutral',
} as const;

/**
 * Connections as things in their own right: the agency's, and each client's.
 *
 * "Google MCC — Smart Code" and "Meta Business — Alhambra" are two rows, with a business-facing state each.
 * The technical message is shown only where the server sent it, which it does only to somebody who can act
 * on it — a project manager sees *needs reconnect* and nothing to misread.
 */
export function ConnectionsPanel() {
  const { t, i18n } = useTranslation();
  const { data, isPending, isError } = useConnectionsQuery();
  const action = useConnectionAction();
  const [adding, setAdding] = useState(false);

  return (
    <section aria-label={t('connections.title')} className="flex flex-col gap-3">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-content text-lg font-semibold">{t('connections.title')}</h2>
          <p className="text-content-secondary text-sm">{t('connections.subtitle')}</p>
        </div>
        <Button variant="secondary" onClick={() => setAdding(true)}>
          {t('connections.addClient')}
        </Button>
      </div>

      {isError ? <Alert tone="danger">{t('connections.loadFailed')}</Alert> : null}
      {action.isError ? (
        <Alert tone="danger">
          {action.error instanceof ApiError ? action.error.message : t('connections.actionFailed')}
        </Alert>
      ) : null}

      {isPending ? null : (data ?? []).length === 0 ? (
        <EmptyState title={t('connections.empty')} description={t('connections.emptyBody')} />
      ) : (
        <ul className="grid gap-3 md:grid-cols-2">
          {(data ?? []).map((connection) => (
            <li key={connection.id}>
              <ConnectionCard
                connection={connection}
                locale={i18n.language}
                busy={action.isPending}
                onAction={(kind) => action.mutate({ id: connection.id, action: kind })}
              />
            </li>
          ))}
        </ul>
      )}

      <AddClientConnection open={adding} onOpenChange={setAdding} />
    </section>
  );
}

function ConnectionCard({
  connection,
  locale,
  busy,
  onAction,
}: {
  connection: Connection;
  locale: string;
  busy: boolean;
  onAction: (action: 'discover' | 'disconnect') => void;
}) {
  const { t } = useTranslation();

  return (
    <article
      aria-label={connection.label}
      className="bg-surface border-line-subtle flex flex-col gap-3 rounded-2xl border p-4 shadow-xs"
    >
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-content truncate font-medium">{connection.label}</p>
          <p className="text-content-tertiary text-xs">
            {connection.owner === 'agency'
              ? t('connections.ownerAgency')
              : t('connections.ownerClient', { name: connection.owner_client?.name ?? '' })}
          </p>
        </div>
        <Badge tone={TONE[connection.state]}>{connection.state_label}</Badge>
      </div>

      <dl className="grid grid-cols-3 gap-2 text-sm">
        <div>
          <dt className="text-content-tertiary text-xs">{t('connections.discovered')}</dt>
          <dd className="ds-numeric">{connection.accounts.discovered}</dd>
        </div>
        <div>
          <dt className="text-content-tertiary text-xs">{t('connections.bound')}</dt>
          <dd className="ds-numeric">{connection.accounts.bound}</dd>
        </div>
        <div>
          <dt className="text-content-tertiary text-xs">{t('connections.unassigned')}</dt>
          <dd className="ds-numeric">{connection.accounts.unassigned}</dd>
        </div>
      </dl>

      {connection.last_checked_at === null ? null : (
        <p className="text-content-tertiary text-xs">
          {t('connections.checked', {
            when: formatRelativeTime(connection.last_checked_at, locale),
          })}
        </p>
      )}

      {connection.advanced === null ? null : (
        <details className="text-xs">
          <summary className="text-content-secondary cursor-pointer">
            {t('connections.advanced')}
          </summary>
          <p className="text-content-tertiary mt-1">{connection.advanced.message}</p>
        </details>
      )}

      <div className="flex gap-2">
        <Button size="sm" variant="secondary" disabled={busy} onClick={() => onAction('discover')}>
          {t('connections.discover')}
        </Button>
        <Button size="sm" variant="ghost" disabled={busy} onClick={() => onAction('disconnect')}>
          {t('connections.disconnect')}
        </Button>
      </div>
    </article>
  );
}

function AddClientConnection({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation();
  const providers = useProvidersQuery();
  const connect = useConnectClientMutation();
  const [term, setTerm] = useState('');
  const clients = useClientsQuery({ search: term, per_page: 8 });
  const [client, setClient] = useState<{ id: string; label: string } | null>(null);
  const [provider, setProvider] = useState('');
  const [values, setValues] = useState<Record<string, string>>({});

  const row = (providers.data ?? []).find((p) => p.provider === provider);
  const keys = row === undefined ? [] : credentialKeysFor(row);

  const done = () => {
    setClient(null);
    setProvider('');
    setValues({});
    onOpenChange(false);
  };

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40" />
        <Dialog.Content
          onCloseAutoFocus={returnFocus}
          className="bg-surface fixed start-1/2 top-1/2 z-50 max-h-[90dvh] w-[calc(100vw-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl p-6 shadow-lg rtl:translate-x-1/2"
        >
          <Dialog.Title className="text-content text-lg font-semibold">
            {t('connections.addClient')}
          </Dialog.Title>
          <Dialog.Description className="text-content-secondary mt-1 text-sm">
            {t('connections.addClientBody')}
          </Dialog.Description>

          <form
            className="mt-4 flex flex-col gap-4"
            onSubmit={(event) => {
              event.preventDefault();

              if (client === null || provider === '') {
                return;
              }

              connect.mutate(
                { provider, client_id: client.id, credentials: values },
                { onSuccess: done },
              );
            }}
          >
            <EntityPicker
              label={t('connections.client')}
              clearLabel={t('campaignControl.clear')}
              value={client}
              onChange={(o) => setClient(o === null ? null : { id: o.id, label: o.label })}
              onSearch={setTerm}
              options={(clients.data?.data ?? []).map((c) => ({ id: c.id, label: c.name }))}
              isLoading={clients.isFetching}
            />

            <Field label={t('connections.platform')} required>
              {(field) => (
                <select
                  {...field}
                  value={provider}
                  onChange={(event) => {
                    setProvider(event.target.value);
                    setValues({});
                  }}
                  className="border-line bg-surface text-content h-9 w-full rounded-md border px-2 text-sm"
                >
                  <option value="">—</option>
                  {(providers.data ?? [])
                    .filter((p) => credentialKeysFor(p).length > 0)
                    .map((p) => (
                      <option key={p.provider} value={p.provider}>
                        {p.label}
                      </option>
                    ))}
                </select>
              )}
            </Field>

            {keys.map((key) => (
              <Field key={key} label={t(`integrations.credentialKeys.${key}`, key)} required>
                {(field) => (
                  <Input
                    {...field}
                    type="password"
                    autoComplete="off"
                    value={values[key] ?? ''}
                    onChange={(event) => setValues({ ...values, [key]: event.target.value })}
                  />
                )}
              </Field>
            ))}

            {connect.isError ? (
              <Alert tone="danger">
                {connect.error instanceof ApiError
                  ? connect.error.message
                  : t('connections.actionFailed')}
              </Alert>
            ) : null}

            <div className="flex justify-end gap-2">
              <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                {t('actions.cancel')}
              </Button>
              <Button
                type="submit"
                disabled={client === null || provider === '' || connect.isPending}
              >
                {t('connections.save')}
              </Button>
            </div>
          </form>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
