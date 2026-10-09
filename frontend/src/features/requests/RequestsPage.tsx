import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useClientsQuery } from '@/features/clients';
import { ApiError } from '@/shared/api/client';
import { useBoardRealtime } from '@/shared/realtime/useBoardRealtime';
import { cn } from '@/shared/lib/cn';
import { formatNumber, formatRate } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Card, CardContent } from '@/shared/ui/card';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { LiveBadge } from '@/shared/ui/live-badge';
import { Skeleton } from '@/shared/ui/skeleton';
import { IntakeFlowChart } from './IntakeFlowChart';
import { RequestComposer } from './RequestComposer';
import { RequestDetail } from './RequestDetail';
import { StatedUrgencyChart } from './StatedUrgencyChart';
import {
  useAnswerMutation,
  useAttachMutation,
  useClientRequestQuery,
  useIntakeQueueQuery,
  usePromiseMutation,
  useAssignOwnerMutation,
  useRaiseTaskMutation,
  useRequestOwnersQuery,
  useRequestVocabularyQuery,
  useSubmitRequestMutation,
  useTriageMutation,
} from './useRequests';
import type { ClientRequestCard } from './types';

/**
 * The client intake queue — and, for a client, their own requests.
 *
 * **One surface for both sides.** The server decides what each may see and tells the page which side it is
 * showing through `is_agency_view`; a separate client page would be a second place for the visibility rules to
 * drift, and the one that drifted would be the one a client reads.
 *
 * The figures are the point. **"Waiting on us" sits beside "waiting on them"**, because a single "open" count
 * lets a conversation stalled on the client hide behind work the agency has not started — and only one of those
 * is the agency's to fix. **Accepted work with no promised date** is published too: "we are on it" is the whole
 * of what those clients have been told.
 */
export function RequestsPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { can, user } = useSession();

  const canSubmit = can(PERMISSIONS.clientRequestsSubmit);

  const [clientId, setClientId] = useState<string | null>(null);
  const [onTheAgencyOnly, setOnTheAgencyOnly] = useState(false);
  const [selected, setSelected] = useState<string | null>(null);
  const [isComposing, setIsComposing] = useState(false);

  const clients = useClientsQuery({ page: 1, per_page: 100, sort: 'name', direction: 'asc' });
  const vocabulary = useRequestVocabularyQuery(canSubmit);
  const queue = useIntakeQueueQuery({ clientId, onTheAgencyOnly }, canSubmit);
  const request = useClientRequestQuery(selected);

  const triage = useTriageMutation();
  const promise = usePromiseMutation();
  const answer = useAnswerMutation();
  const attach = useAttachMutation();
  const raiseTask = useRaiseTaskMutation();
  const assignOwner = useAssignOwnerMutation();
  const submit = useSubmitRequestMutation();

  /*
   * Who the open request can be given to, from the server — see `OwnerDirectory`.
   *
   * Above the early return and gated by the server's own answer rather than by a permission read here: hooks
   * run in the same order on every render, and the queue is what says whether this reader may triage at all.
   * The query is disabled until both are true, so a client reading their own thread asks for nothing.
   */
  const owners = useRequestOwnersQuery(selected, queue.data?.can_triage ?? false);

  /*
   * Live, where the socket is configured. The event is a nudge with no rows in it, so the only response is
   * to read the board again over the authenticated endpoint — see `useBoardRealtime`.
   */
  const isLive = useBoardRealtime('client_requests', user?.active_workspace?.id ?? null);

  const error = useMemo(() => {
    for (const mutation of [triage, promise, answer, raiseTask, assignOwner, submit]) {
      if (mutation.error instanceof ApiError && mutation.error.message !== '') {
        return mutation.error.message;
      }
    }

    return null;
  }, [triage, promise, answer, raiseTask, assignOwner, submit]);

  if (!canSubmit) {
    return (
      <EmptyState title={t('requests.denied.title')} description={t('requests.denied.body')} />
    );
  }

  const load = queue.data?.load;
  const isAgencyView = queue.data?.is_agency_view ?? true;
  const canTriage = queue.data?.can_triage ?? false;

  /*
   * A client has exactly one client, so the composer locks it. Taken from the only client the queue returned
   * rather than from a session field: it is the same answer, resolved by the rule the server already applied.
   */
  const lockedClientId = isAgencyView
    ? null
    : (queue.data?.columns.flatMap((column) => column.requests)[0]?.client_id ??
      clients.data?.data[0]?.id ??
      null);

  const card = (entry: ClientRequestCard) => (
    <li key={entry.id}>
      <button
        type="button"
        onClick={() => setSelected(entry.id)}
        aria-pressed={selected === entry.id}
        className={cn(
          'bg-surface border-line-subtle w-full rounded-xl border p-3 text-start shadow-xs',
          'ease-standard transition-[border-color,box-shadow] duration-[var(--ds-duration-fast)]',
          'hover:border-line-strong focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none',
          selected === entry.id && 'border-accent ring-accent/25 ring-1',
        )}
      >
        <span className="text-content block text-sm font-medium">{entry.title}</span>
        <span className="text-content-tertiary mt-1 block text-xs">
          {entry.client_name} · {entry.kind_label}
        </span>
        <span className="mt-2 flex flex-wrap items-center gap-1.5">
          {/*
            The client's word, labelled. Never a priority badge: the agency's own priority lives on the tasks
            raised from this request, on the agency's own board.
          */}
          <Badge tone={entry.stated_urgency === 'urgent' ? 'warning' : 'neutral'}>
            {t('requests.card.clientSaid', { urgency: entry.stated_urgency_label })}
          </Badge>
          {entry.is_overdue ? (
            <Badge tone="danger">{t('requests.card.pastPromise')}</Badge>
          ) : entry.promised_at === null ? (
            <Badge tone="warning">{t('requests.card.noPromise')}</Badge>
          ) : null}
          {entry.is_unanswered ? (
            <Badge tone="danger">
              {t('requests.card.unanswered', { days: entry.waiting_for_answer_days })}
            </Badge>
          ) : null}
        </span>
      </button>
    </li>
  );

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-4">
        <div>
          <h1 className="text-content text-2xl font-semibold">
            {t(isAgencyView ? 'requests.title' : 'requests.titleClient')}
          </h1>
          <p className="text-content-secondary mt-1 max-w-2xl text-sm">
            {t(isAgencyView ? 'requests.subtitle' : 'requests.subtitleClient')}
          </p>
          <LiveBadge isLive={isLive} />
        </div>

        {!isAgencyView ? null : (
          <div className="flex flex-wrap gap-6">
            <ChoiceGroup label={t('requests.scope.client')}>
              <div className="flex flex-wrap gap-2">
                <Choice isSelected={clientId === null} onSelect={() => setClientId(null)}>
                  {t('requests.scope.allClients')}
                </Choice>
                {(clients.data?.data ?? []).map((client) => (
                  <Choice
                    key={client.id}
                    isSelected={clientId === client.id}
                    onSelect={() => setClientId(client.id)}
                  >
                    {client.name}
                  </Choice>
                ))}
              </div>
            </ChoiceGroup>

            <ChoiceGroup label={t('requests.scope.waitingOn')}>
              <div className="flex flex-wrap gap-2">
                <Choice isSelected={!onTheAgencyOnly} onSelect={() => setOnTheAgencyOnly(false)}>
                  {t('requests.scope.everything')}
                </Choice>
                <Choice isSelected={onTheAgencyOnly} onSelect={() => setOnTheAgencyOnly(true)}>
                  {t('requests.scope.onUs')}
                </Choice>
              </div>
            </ChoiceGroup>
          </div>
        )}
      </header>

      {queue.isError ? <Alert tone="danger">{t('requests.loadFailed')}</Alert> : null}

      {queue.isPending || load === undefined || queue.data === undefined ? (
        <Skeleton className="h-32 w-full" />
      ) : (
        <>
          {isAgencyView ? (
            <section
              aria-label={t('requests.counts')}
              className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
            >
              {[
                /*
                 * The pair that stops a stall hiding. Side by side, and in this order: the first is the agency's
                 * to fix and the second is a conversation it has to go and have.
                 */
                { key: 'onUs', value: load.on_the_agency, tone: 'danger' as const },
                { key: 'onThem', value: load.on_the_client, tone: 'warning' as const },
                { key: 'unpromised', value: load.unpromised, tone: 'warning' as const },
                { key: 'overdue', value: load.overdue, tone: 'danger' as const },
              ].map((figure) => (
                <Card key={figure.key}>
                  <CardContent className="flex flex-col gap-1 pt-5">
                    <span className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
                      {t(`requests.count.${figure.key}`)}
                    </span>
                    <span className="text-content text-2xl font-semibold">
                      {formatNumber(figure.value, locale)}
                    </span>
                    {figure.value > 0 ? (
                      <Badge tone={figure.tone}>{t(`requests.count.${figure.key}Note`)}</Badge>
                    ) : null}
                  </CardContent>
                </Card>
              ))}
            </section>
          ) : null}

          {isAgencyView ? (
            <p className="text-content-tertiary text-sm">
              {/*
                Both rates say so in words rather than printing nought percent. A queue with no promises cannot be
                late — nor said to be on time — so the share is published beside the delivery rate.
              */}
              {load.delivery_rate === null
                ? t('requests.rates.noneClosed')
                : t('requests.rates.delivered', {
                    rate: formatRate(load.delivery_rate, locale),
                    closed: formatNumber(load.delivered + load.declined + load.withdrawn, locale),
                  })}{' '}
              {load.promised_share === null
                ? t('requests.rates.nonePromised')
                : t('requests.rates.promised', {
                    rate: formatRate(load.promised_share, locale),
                  })}
            </p>
          ) : null}

          {isAgencyView ? (
            <div className="grid gap-6 xl:grid-cols-2">
              <IntakeFlowChart load={load} states={vocabulary.data?.states ?? []} />
              <StatedUrgencyChart load={load} urgencies={vocabulary.data?.urgencies ?? []} />
            </div>
          ) : null}

          <section
            aria-label={t('requests.board')}
            tabIndex={0}
            className="flex gap-4 overflow-x-auto pb-2"
          >
            {queue.data.columns.map((column) => (
              <div key={column.state} className="flex w-64 shrink-0 flex-col gap-2">
                <h2 className="text-content-secondary flex items-center justify-between text-xs font-medium tracking-wide uppercase">
                  {column.label}
                  {/* The whole column, from SQL. The cards below are a page of it. */}
                  <span className="text-content-tertiary tabular-nums">
                    {formatNumber(column.total, locale)}
                  </span>
                </h2>

                <ul className="flex flex-col gap-2">{column.requests.map(card)}</ul>
              </div>
            ))}
          </section>
        </>
      )}

      <RequestDetail
        /* Keyed on the selection, so a reply typed about one client cannot travel to another. */
        key={selected ?? 'none'}
        request={selected === null ? undefined : request.data}
        vocabulary={vocabulary.data}
        isLoading={selected !== null && request.isPending}
        isAgencyView={isAgencyView}
        canTriage={canTriage}
        onTriage={(state, reason) => {
          if (selected !== null) {
            triage.mutate({
              requestId: selected,
              state,
              ...(reason === undefined ? {} : { reason }),
            });
          }
        }}
        onPromise={(promisedAt) => {
          if (selected !== null) {
            promise.mutate({ requestId: selected, promisedAt });
          }
        }}
        {...(canTriage
          ? {
              owners: selected === null ? undefined : owners.data,
              onAssign: (ownerId: string | null) => {
                if (selected !== null) {
                  assignOwner.mutate({ requestId: selected, ownerId });
                }
              },
            }
          : {})}
        onAnswer={(audience, body) => {
          if (selected !== null) {
            answer.mutate({ requestId: selected, audience, body });
          }
        }}
        onAttach={(audience, file) => {
          if (selected !== null) {
            attach.mutate({ requestId: selected, audience, file });
          }
        }}
        /*
         * Its own error, kept apart from the rest. Every attachment refusal says what to send instead — a smaller
         * file, a PNG rather than an SVG — and folding it into the shared banner would put that sentence a long
         * way from the control that caused it.
         */
        attachError={
          attach.error instanceof ApiError && attach.error.message !== ''
            ? attach.error.message
            : null
        }
        isAttaching={attach.isPending}
        onRaiseTask={(title) => {
          if (selected !== null) {
            raiseTask.mutate({ requestId: selected, title });
          }
        }}
        error={error}
      />

      {isComposing ? (
        <RequestComposer
          vocabulary={vocabulary.data}
          clients={(clients.data?.data ?? []).map((client) => ({
            id: client.id,
            name: client.name,
          }))}
          lockedClientId={lockedClientId}
          isSaving={submit.isPending}
          error={error}
          onSubmit={(input) => {
            submit.mutate(input, { onSuccess: () => setIsComposing(false) });
          }}
        />
      ) : (
        <div>
          <button
            type="button"
            onClick={() => setIsComposing(true)}
            className="text-accent text-sm font-medium hover:underline"
          >
            {t(isAgencyView ? 'requests.composer.open' : 'requests.composer.openClient')}
          </button>
        </div>
      )}
    </div>
  );
}
