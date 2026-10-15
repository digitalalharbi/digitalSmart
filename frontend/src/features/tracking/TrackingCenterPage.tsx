import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useClientsQuery } from '@/features/clients';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { PageHeader } from '@/shared/ui/page-header';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { ContainerReadiness } from './ContainerReadiness';
import { ConversionEditor } from './ConversionEditor';
import { IssueList } from './IssueList';
import { ObservedEventsChart } from './ObservedEventsChart';
import { RemoveSourceDialog } from './RemoveSourceDialog';
import { SourceRegistration } from './SourceRegistration';
import { useSyncSourceMutation, useTrackingHealthQuery } from './useTracking';
import type { EventDefinition, Freshness, MeasurementSource, SyncResult } from './types';

const FRESHNESS_TONE: Record<Freshness, 'success' | 'neutral' | 'warning' | 'danger'> = {
  live: 'success',
  synced: 'neutral',
  stale: 'warning',
  // Reachable and reporting nothing. The same weight as never connected, because the
  // consequence is the same: every report built on it is empty.
  silent: 'danger',
  never_synced: 'danger',
};

/** A date, or a dash. "Never" and "we do not know" are both better said than rendered as 0. */
function formatDate(value: string | null, locale: string): string {
  if (value === null) {
    return '—';
  }

  const parsed = Date.parse(value);

  return Number.isNaN(parsed)
    ? value
    : new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(parsed);
}

/**
 * The Tracking Center.
 *
 * One page answering the question an agency cannot otherwise answer: *is any of this actually
 * working?* Three panels, in the order somebody needs them — what is wrong, what is measuring,
 * and what counts as success.
 *
 * The issue list comes first deliberately. A page that opened on a tidy table of sources would
 * invite the conclusion that everything is fine, which is precisely the conclusion that costs an
 * agency a quarter of a client's spend.
 */
export function TrackingCenterPage() {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  /* Searched, never listed: the 101st client is not a typo. */
  const [client, setClient] = useState<{ id: string; label: string } | null>(null);
  const [clientTerm, setClientTerm] = useState('');
  const clientId = client?.id ?? null;
  const [editing, setEditing] = useState<EventDefinition | null | 'new'>(null);
  const [lastSync, setLastSync] = useState<{ name: string; result: SyncResult } | null>(null);

  const clients = useClientsQuery({ search: clientTerm, per_page: 8 });
  const scope = useMemo(() => ({ client_id: clientId }), [clientId]);
  const health = useTrackingHealthQuery(scope);
  const sync = useSyncSourceMutation();
  /*
   * Removal goes through a dialog: it sits beside "Read now" in the same row and it is the irreversible one.
   * What it takes with it is named there — see `RemoveSourceDialog`.
   */
  const [removing, setRemoving] = useState<MeasurementSource | null>(null);
  const canManage = can(PERMISSIONS.trackingManage);

  const clientOptions = clients.data?.data ?? [];

  function runSync(source: MeasurementSource) {
    sync.mutate(source.id, {
      onSuccess: (result) => setLastSync({ name: source.name, result }),
    });
  }

  return (
    <div className="ds-page">
      <PageHeader
        title={t('tracking.title')}
        description={t('tracking.subtitle')}
        filters={
          <EntityPicker
            label={t('tracking.scope.client')}
            clearLabel={t('actions.clear')}
            placeholder={t('tracking.scope.allClients')}
            value={client}
            onChange={(option) =>
              setClient(option === null ? null : { id: option.id, label: option.label })
            }
            onSearch={setClientTerm}
            options={clientOptions.map((c) => ({ id: c.id, label: c.name }))}
            isLoading={clients.isFetching}
          />
        }
      />

      <RemoveSourceDialog
        source={removing}
        onOpenChange={(open) => {
          if (!open) {
            setRemoving(null);
          }
        }}
      />

      {lastSync === null ? null : <SyncReport name={lastSync.name} result={lastSync.result} />}

      {health.isLoading ? (
        <Skeleton className="h-48 w-full" />
      ) : health.isError ? (
        <Alert tone="danger">{t('tracking.loadFailed')}</Alert>
      ) : health.data === undefined ? null : (
        <>
          <section className="flex flex-col gap-3" aria-labelledby="tracking-issues">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 id="tracking-issues" className="text-content text-lg font-semibold">
                {t('tracking.issues.title')}
              </h2>
              <p className="text-content-tertiary text-xs">
                {t('tracking.issues.window', { days: health.data.window_days })}
              </p>
            </div>
            <IssueList issues={health.data.issues} />
          </section>

          <section className="flex flex-col gap-3" aria-labelledby="tracking-sources">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 id="tracking-sources" className="text-content text-lg font-semibold">
                {t('tracking.sources.title')}
              </h2>
              <SourceRegistration
                clientId={clientId}
                clients={clientOptions}
                onSearchClients={setClientTerm}
                isSearching={clients.isFetching}
              />
            </div>

            {health.data.sources.length === 0 ? (
              <EmptyState
                title={t('tracking.sources.emptyTitle')}
                description={t('tracking.sources.emptyBody')}
              />
            ) : (
              <Card
                role="group"
                aria-label={t('tracking.sources.title')}
                tabIndex={0}
                className="overflow-x-auto"
              >
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-content-tertiary border-line-subtle border-b text-start text-xs">
                      <th scope="col" className="px-4 py-3 text-start font-medium">
                        {t('tracking.sources.name')}
                      </th>
                      <th scope="col" className="px-4 py-3 text-start font-medium">
                        {t('tracking.sources.kind')}
                      </th>
                      <th scope="col" className="px-4 py-3 text-start font-medium">
                        {t('tracking.sources.freshness')}
                      </th>
                      <th scope="col" className="px-4 py-3 text-start font-medium">
                        {t('tracking.sources.lastEvent')}
                      </th>
                      <th scope="col" className="px-4 py-3 text-end font-medium">
                        {t('tracking.sources.actions')}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {health.data.sources.map((source) => (
                      <tr key={source.id} className="border-line-subtle border-b last:border-0">
                        <td className="px-4 py-3">
                          <p className="text-content font-medium">{source.name}</p>
                          <p className="text-content-tertiary text-xs">{source.external_id}</p>
                        </td>
                        <td className="px-4 py-3">
                          <div className="flex flex-wrap items-center gap-1.5">
                            <span className="text-content-secondary">{source.kind_label}</span>
                            {/*
                             * Only a store or a CRM can speak for revenue. An analytics property
                             * reports what a browser said a sale was worth; neither is an invoice.
                             */}
                            {source.is_revenue_authority ? (
                              <Badge tone="accent">{t('tracking.sources.revenueAuthority')}</Badge>
                            ) : null}
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <Badge tone={FRESHNESS_TONE[source.freshness]}>
                            {source.freshness_label}
                          </Badge>
                        </td>
                        <td className="text-content-secondary px-4 py-3">
                          {formatDate(source.last_event_at, i18n.language)}
                        </td>
                        <td className="px-4 py-3 text-end">
                          <div className="flex justify-end gap-1.5">
                            <Button
                              variant="secondary"
                              size="sm"
                              onClick={() => runSync(source)}
                              disabled={sync.isPending}
                            >
                              {t('tracking.sources.sync')}
                            </Button>
                            {/*
                              Registering a source is `tracking.manage`, and so is unregistering one. A reader
                              who can only look at the tracking picture is offered neither rather than a
                              disabled control that would always be refused.
                            */}
                            {canManage ? (
                              <Button
                                variant="ghost"
                                size="sm"
                                className="text-danger"
                                onClick={() => setRemoving(source)}
                              >
                                {t('tracking.sources.remove')}
                              </Button>
                            ) : null}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Card>
            )}
          </section>

          {/*
            Fourth panel, and last of the four deliberately: it is the only one that reaches a provider. The
            three above are derived from this product's own tables and always answer, so a slow or unauthorised
            Tag Manager cannot empty the page of facts it already had.
          */}
          <section className="flex flex-col gap-3" aria-labelledby="tracking-containers">
            <div className="flex flex-col gap-1">
              <h2 id="tracking-containers" className="text-content text-lg font-semibold">
                {t('tracking.containers.title')}
              </h2>
              <p className="text-content-secondary text-sm">{t('tracking.containers.subtitle')}</p>
            </div>

            <ContainerReadiness sources={health.data.sources} canManage={canManage} />
          </section>

          <section className="flex flex-col gap-3" aria-labelledby="tracking-conversions">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 id="tracking-conversions" className="text-content text-lg font-semibold">
                {t('tracking.conversions.title')}
              </h2>
              <Button variant="secondary" size="sm" onClick={() => setEditing('new')}>
                {t('tracking.conversions.define')}
              </Button>
            </div>

            {health.data.definitions.length === 0 ? (
              <EmptyState
                title={t('tracking.conversions.emptyTitle')}
                description={t('tracking.conversions.emptyBody')}
              />
            ) : (
              <ObservedEventsChart
                definitions={health.data.definitions}
                windowDays={health.data.window_days}
              />
            )}

            {health.data.definitions.length === 0 ? null : (
              <ul className="grid gap-3 sm:grid-cols-2">
                {health.data.definitions.map((definition) => (
                  <li key={definition.id}>
                    <ConversionCard
                      definition={definition}
                      onEdit={() => setEditing(definition)}
                      windowDays={health.data.window_days}
                    />
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}

      {editing === null ? null : (
        <ConversionEditor
          definition={editing === 'new' ? null : editing}
          clientId={clientId}
          clients={clientOptions}
          onSearchClients={setClientTerm}
          isSearching={clients.isFetching}
          sources={health.data?.sources ?? []}
          onClose={() => setEditing(null)}
        />
      )}
    </div>
  );
}

/** What one conversion counts, and whether it has ever been seen. */
function ConversionCard({
  definition,
  onEdit,
  windowDays,
}: {
  definition: EventDefinition;
  onEdit: () => void;
  windowDays: number;
}) {
  const { t } = useTranslation();

  return (
    <Card className="flex h-full flex-col p-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="text-content font-medium">{definition.name}</p>
          <p className="text-content-tertiary text-xs">{definition.normalized_event_label}</p>
        </div>
        <div className="flex flex-wrap gap-1.5">
          {definition.is_primary ? (
            <Badge tone="accent">{t('tracking.conversions.primary')}</Badge>
          ) : null}
          {/*
           * The distinction that stops a cost per WhatsApp click being read as a cost per lead —
           * the commonest way an agency overstates its results.
           */}
          {definition.is_intent_signal_only ? (
            <Badge tone="warning">{t('tracking.conversions.intentOnly')}</Badge>
          ) : null}
        </div>
      </div>

      <p className="text-content-secondary mt-3 text-xs">
        {t('tracking.conversions.countsFrom', {
          names: definition.mappings.map((mapping) => mapping.raw_event_name).join(', '),
        })}
      </p>

      <div className="mt-auto flex items-end justify-between gap-2 pt-3">
        <div>
          <p className="text-content text-xl font-semibold tabular-nums">
            {/*
             * Null means nobody asked about a window; 0 means asked, and never seen. Rendering
             * both as "0" would turn "we have not looked" into "it never happened".
             */}
            {definition.observed_count ?? '—'}
          </p>
          <p className="text-content-tertiary text-xs">
            {t('tracking.conversions.inWindow', { days: windowDays })}
          </p>
        </div>
        <Button variant="ghost" size="sm" onClick={onEdit}>
          {t('tracking.conversions.edit')}
        </Button>
      </div>
    </Card>
  );
}

/**
 * What a sync just did.
 *
 * The caveats are the reason this exists rather than a toast saying "synced". A run can succeed
 * completely and still have read figures that are estimates, and the person who pressed the button
 * is the one who needs to know that before they quote a number.
 */
function SyncReport({ name, result }: { name: string; result: SyncResult }) {
  const { t } = useTranslation();

  if (result.status === 'failed') {
    return (
      <Alert tone="danger">
        <p className="font-medium">{t('tracking.sync.failed', { name })}</p>
        {result.failure_message === null ? null : <p className="mt-1">{result.failure_message}</p>}
      </Alert>
    );
  }

  if (result.status === 'skipped') {
    return <Alert tone="info">{t('tracking.sync.skipped', { name })}</Alert>;
  }

  return (
    <Alert tone={result.caveats.length > 0 ? 'warning' : 'info'}>
      <p className="font-medium">
        {t('tracking.sync.done', {
          name,
          rows: result.rows_written,
          days: result.days_read,
        })}{' '}
        <Badge tone="neutral">{result.source_label}</Badge>
      </p>
      {result.caveats.length === 0 ? null : (
        <ul className="mt-2 list-disc ps-5">
          {result.caveats.map((caveat) => (
            <li key={caveat}>{caveat}</li>
          ))}
        </ul>
      )}
    </Alert>
  );
}
