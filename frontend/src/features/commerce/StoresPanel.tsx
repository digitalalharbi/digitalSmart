import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Client } from '@/features/clients';
import { PERMISSIONS, useSession } from '@/features/auth';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { Field } from '@/shared/ui/field';
import { Skeleton } from '@/shared/ui/skeleton';
import {
  useConnectStoreMutation,
  useConnectableStoresQuery,
  useReadStoreMutation,
  useStoresQuery,
} from './useCommerce';
import type { ConnectableStore, Store, StoreReadResult } from './types';

/**
 * Which stores this workspace reads, and the one place a new one is connected.
 *
 * **This was the missing half of the commerce module.** A workspace could connect Salla on the integrations
 * page, the webhook endpoint could authenticate its deliveries, the reconciliation read could walk its orders
 * and this very page could report them — and nothing could say *which client a merchant belongs to*. A `stores`
 * row could only be written by a seeder, so every delivery a real installation received was rejected as
 * `unknown_store` and every figure above was empty, with nothing on any screen to explain why.
 *
 * **Reading is on demand as well as on a schedule.** Webhooks are faster and they are not sufficient: one
 * dropped delivery, one deploy during a busy hour, one platform incident, and a client's revenue is quietly
 * short for that window. `commerce:sync` is the same read nightly; this is the button for the morning somebody
 * notices a figure looks low.
 *
 * **What a read brought back is reported, not reduced to a tick.** Partial means orders landed and are kept;
 * skipped means the platform has no adapter to read with; failed names what the platform said. And a sandbox
 * read says sandbox — fixtures are never presented as a live figure.
 */
export function StoresPanel({ clientId, clients }: { clientId: string | null; clients: Client[] }) {
  const { t } = useTranslation();
  const { can } = useSession();
  const canManage = can(PERMISSIONS.workspacesUpdate);

  const stores = useStoresQuery(clientId);
  const [isConnecting, setIsConnecting] = useState(false);
  const connectable = useConnectableStoresQuery(isConnecting && canManage);
  const connect = useConnectStoreMutation();
  const read = useReadStoreMutation();

  const [chosenClient, setChosenClient] = useState<string | null>(clientId);
  const [lastRead, setLastRead] = useState<{ name: string; result: StoreReadResult } | null>(null);

  const targetClient = chosenClient ?? clientId;

  const error = [connect.error, read.error].find(
    (candidate): candidate is ApiError => candidate instanceof ApiError,
  );

  /*
   * A `403` here is the page's sentence to say, not this panel's: a reader without `analytics.view` is told
   * once that their role does not reach store revenue, and a second alert about the store list adds nothing
   * except the impression that something broke.
   */
  if (stores.error instanceof ApiError && stores.error.status === 403) {
    return null;
  }

  function connectOne(store: ConnectableStore) {
    if (targetClient === null) {
      return;
    }

    connect.mutate(
      { provider: store.provider, external_id: store.external_id, client_id: targetClient },
      { onSuccess: () => setIsConnecting(false) },
    );
  }

  return (
    <section className="flex flex-col gap-3" aria-labelledby="commerce-stores">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 id="commerce-stores" className="text-content text-lg font-semibold">
            {t('commerce.stores.title')}
          </h2>
          <p className="text-content-secondary mt-1 text-sm">{t('commerce.stores.subtitle')}</p>
        </div>

        {canManage && !isConnecting ? (
          <Button variant="secondary" size="sm" onClick={() => setIsConnecting(true)}>
            {t('commerce.stores.connect')}
          </Button>
        ) : null}
      </div>

      {error === undefined ? null : <Alert tone="danger">{error.message}</Alert>}

      {lastRead === null ? null : <ReadReport name={lastRead.name} result={lastRead.result} />}

      {isConnecting ? (
        <Card className="flex flex-col gap-4 p-4">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p className="text-content text-sm font-medium">{t('commerce.stores.pickTitle')}</p>
              <p className="text-content-secondary mt-1 text-sm">
                {t('commerce.stores.pickSubtitle')}
              </p>
            </div>
            <Button variant="ghost" size="sm" onClick={() => setIsConnecting(false)}>
              {t('actions.cancel')}
            </Button>
          </div>

          {clientId === null ? (
            <Field label={t('commerce.stores.client')} hint={t('commerce.stores.clientHint')}>
              {(props) => (
                <select
                  {...props}
                  className="border-line bg-surface text-content rounded-lg border px-3 py-2 text-sm"
                  value={chosenClient ?? ''}
                  onChange={(event) =>
                    setChosenClient(event.target.value === '' ? null : event.target.value)
                  }
                >
                  <option value="">{t('commerce.stores.chooseClient')}</option>
                  {clients.map((client) => (
                    <option key={client.id} value={client.id}>
                      {client.name}
                    </option>
                  ))}
                </select>
              )}
            </Field>
          ) : null}

          {connectable.isLoading ? (
            <Skeleton className="h-24 w-full" />
          ) : connectable.isError ? (
            <Alert tone="warning">{t('commerce.stores.discoveryFailed')}</Alert>
          ) : (connectable.data ?? []).length === 0 ? (
            /*
             * Nothing connected is the ordinary state of a new installation, and it is fixed on the
             * integrations page — so this says where to go rather than reporting a failure nobody can act on
             * from here.
             */
            <Alert tone="info">{t('commerce.stores.nothingDiscoverable')}</Alert>
          ) : (
            <ul className="flex flex-col gap-2">
              {(connectable.data ?? []).map((store) => (
                <li
                  key={`${store.provider}:${store.external_id}`}
                  className="border-line-subtle flex flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-2"
                >
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-1.5">
                      <p className="text-content truncate text-sm font-medium">{store.name}</p>
                      <Badge>{store.provider_label}</Badge>
                      <Badge tone="neutral">{store.currency}</Badge>
                    </div>
                    <p className="text-content-tertiary truncate text-xs">
                      {store.domain ?? store.external_id}
                    </p>
                  </div>

                  {store.is_registered ? (
                    /*
                     * Shown rather than hidden, with the client that has it. A merchant missing from this list
                     * reads as "the connection cannot see it", and somebody then goes looking at the platform
                     * for a problem that is not there.
                     */
                    <span className="text-content-secondary text-xs">
                      {t('commerce.stores.alreadyConnected', {
                        client: store.registered_to_client_name ?? '',
                      })}
                    </span>
                  ) : (
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={targetClient === null || connect.isPending}
                      onClick={() => connectOne(store)}
                    >
                      {t('commerce.stores.use')}
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Card>
      ) : null}

      {stores.isLoading ? (
        <Skeleton className="h-24 w-full" />
      ) : stores.isError ? (
        <Alert tone="danger">{t('commerce.stores.loadFailed')}</Alert>
      ) : (stores.data ?? []).length === 0 ? (
        <EmptyState
          title={t('commerce.stores.emptyTitle')}
          description={t('commerce.stores.emptyBody')}
        />
      ) : (
        <ul
          aria-label={t('commerce.stores.listLabel')}
          className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
        >
          {(stores.data ?? []).map((store) => (
            <li
              key={store.id}
              className="border-line-subtle bg-surface flex flex-col gap-3 rounded-2xl border p-4"
            >
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="text-content truncate text-sm font-medium" dir="auto">
                    {store.name}
                  </p>
                  <p className="text-content-tertiary truncate text-xs" dir="auto">
                    {t('commerce.stores.forClient', { client: store.client_name })}
                  </p>
                </div>
                <div className="flex shrink-0 gap-1">
                  <Badge>{store.provider_label}</Badge>
                  <Badge tone="neutral">{store.currency}</Badge>
                </div>
              </div>

              <div className="flex items-center justify-between gap-2">
                <p className="text-content-secondary flex min-w-0 items-center gap-2 text-xs">
                  <span
                    aria-hidden
                    className={
                      'size-2 shrink-0 rounded-full ' +
                      (store.last_synced_at === null
                        ? 'bg-warning'
                        : store.orders_synced_through === null
                          ? 'bg-warning'
                          : 'bg-success')
                    }
                  />
                  <span className="truncate">
                    <LastRead store={store} />
                  </span>
                </p>
                {canManage ? (
                  <Button
                    variant="secondary"
                    size="sm"
                    /*
                     * A store whose platform has no sync adapter cannot be read at all — its webhooks still
                     * arrive. The control says so rather than offering a read that can only answer "skipped".
                     */
                    disabled={!store.is_readable || read.isPending}
                    onClick={() =>
                      read.mutate(store.id, {
                        onSuccess: (result) => setLastRead({ name: store.name, result }),
                      })
                    }
                  >
                    {store.is_readable
                      ? t('commerce.stores.read')
                      : t('commerce.stores.cannotRead')}
                  </Button>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/**
 * When this store was last read, and how far its orders got. Never a date it does not have.
 *
 * Asked and answered are two different facts. A read that reached the platform and brought no orders back
 * leaves the watermark alone, and saying "read an hour ago" without that is how a failing store looks fine.
 */
function LastRead({ store }: { store: Store }) {
  const { t, i18n } = useTranslation();

  if (store.last_synced_at === null) {
    return <>{t('commerce.stores.neverRead')}</>;
  }

  const when = new Intl.DateTimeFormat(i18n.language, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(store.last_synced_at));

  return (
    <>
      {store.orders_synced_through === null
        ? t('commerce.stores.readWithoutOrders', { when })
        : t('commerce.stores.lastRead', { when })}
    </>
  );
}

/** What the read brought back, in the four states it can be in. */
function ReadReport({ name, result }: { name: string; result: StoreReadResult }) {
  const { t } = useTranslation();

  const tone =
    result.status === 'succeeded' ? 'info' : result.status === 'failed' ? 'danger' : 'warning';

  return (
    <Alert tone={tone}>
      {result.status === 'skipped'
        ? t('commerce.stores.readSkipped', { name })
        : result.status === 'failed'
          ? t('commerce.stores.readFailed', { name, reason: result.failure_message ?? '' })
          : t(
              result.status === 'partial'
                ? 'commerce.stores.readPartial'
                : 'commerce.stores.readDone',
              {
                name,
                orders: result.orders_written,
                products: result.products_written,
                customers: result.customers_written,
                reason: result.failure_message ?? '',
              },
            )}
    </Alert>
  );
}
