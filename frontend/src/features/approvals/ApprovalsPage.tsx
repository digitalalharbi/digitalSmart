import { Plus } from 'lucide-react';
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
import { Button } from '@/shared/ui/button';
import { Card, CardContent } from '@/shared/ui/card';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { LiveBadge } from '@/shared/ui/live-badge';
import { Skeleton } from '@/shared/ui/skeleton';
import { PageHeader } from '@/shared/ui/page-header';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { ApprovalDetail } from './ApprovalDetail';
import { KindBreakdown } from './KindBreakdown';
import { RequestApprovalForm } from './RequestApprovalForm';
import { WaitingChart } from './WaitingChart';
import {
  useApprovalQuery,
  useApprovalQueueQuery,
  useApprovalVocabularyQuery,
  useDecideApprovalMutation,
  useCarryOutMutation,
  useEnactmentMutation,
  useRequestApprovalMutation,
  useWithdrawApprovalMutation,
} from './useApprovals';
import type { ApprovalRow } from './types';

/**
 * The approval queue.
 *
 * **Two lists, side by side, and the second is the one nothing else in this space shows.** Requests waiting for
 * a decision are a process question. Approved changes waiting for somebody to go and make them are a *client*
 * question: until a person records the change, nothing has happened, and an agency that treats approval as the
 * end of the story will tell a client a campaign was stopped while it keeps spending.
 *
 * This product does not write to the ad platforms, so it cannot close that gap by acting. It can only refuse to
 * hide it — which is what the outstanding count, its age, and the sentence on every request are for.
 */
export function ApprovalsPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { can, user } = useSession();

  const canView = can(PERMISSIONS.approvalsView);
  const canDecide = can(PERMISSIONS.approvalsDecide);
  const canRequest = can(PERMISSIONS.approvalsRequest);
  const canCarryOut = can(PERMISSIONS.providerWritesExecute);

  /* Searched, never listed: a hundred-client agency cannot be a row of buttons, and the 101st is not a typo. */
  const [client, setClient] = useState<{ id: string; label: string } | null>(null);
  const [clientTerm, setClientTerm] = useState('');
  const clientId = client?.id ?? null;
  const [spendOnly, setSpendOnly] = useState(false);
  const [selected, setSelected] = useState<string | null>(null);
  const [asking, setAsking] = useState(false);

  const clients = useClientsQuery({ search: clientTerm, per_page: 8 });
  const vocabulary = useApprovalVocabularyQuery(canView);
  const queue = useApprovalQueueQuery({ clientId, spendOnly }, canView);
  const request = useApprovalQuery(selected);

  const decide = useDecideApprovalMutation();
  const withdraw = useWithdrawApprovalMutation();
  const enact = useEnactmentMutation();
  const carryOut = useCarryOutMutation();
  const ask = useRequestApprovalMutation();

  /*
   * Live, where the socket is configured. The event is a nudge with no rows in it, so the only response is
   * to read the board again over the authenticated endpoint — see `useBoardRealtime`.
   */
  const isLive = useBoardRealtime('approvals', user?.active_workspace?.id ?? null);

  const error = useMemo(() => {
    for (const mutation of [decide, withdraw, enact, ask, carryOut]) {
      if (mutation.error instanceof ApiError && mutation.error.message !== '') {
        return mutation.error.message;
      }
    }

    return null;
  }, [decide, withdraw, enact, ask, carryOut]);

  /*
   * Keyed on its subject rather than cleared in an effect. The detail remounts when the selection changes, but
   * this mutation lives on the page, so a platform's answer about one campaign must not be read as an answer
   * about the next request a person opens.
   */
  const carriedOut =
    carryOut.data !== undefined && carryOut.variables?.requestId === selected
      ? carryOut.data.outcome
      : undefined;

  if (!canView) {
    return (
      <EmptyState
        title={t('approvals.denied.title')}
        level={1}
        description={t('approvals.denied.body')}
      />
    );
  }

  const load = queue.data?.load;

  const row = (entry: ApprovalRow) => (
    <li key={entry.id}>
      <button
        type="button"
        onClick={() => setSelected(entry.id)}
        aria-pressed={selected === entry.id}
        className={cn(
          'bg-surface border-line-subtle w-full rounded-2xl border p-3 text-start shadow-xs',
          'ease-standard transition-[border-color,box-shadow] duration-[var(--ds-duration-fast)]',
          'hover:border-line-strong focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none',
          selected === entry.id && 'border-accent ring-accent/25 ring-1',
        )}
      >
        <span className="text-content block text-sm font-medium">{entry.title}</span>
        <span className="text-content-tertiary mt-1 block text-xs">
          {entry.client_name ?? t('approvals.detail.internal')}
          {entry.requested_by_name === null ? '' : ` · ${entry.requested_by_name}`}
        </span>
        <span className="mt-2 flex flex-wrap items-center gap-1.5">
          <Badge tone="neutral">{entry.kind_label}</Badge>
          {entry.touches_live_spend ? (
            <Badge tone="warning">{t('approvals.kinds.spend')}</Badge>
          ) : null}
          {entry.age_label === null ? null : (
            <Badge tone={entry.age === 'stale' ? 'danger' : 'neutral'}>{entry.age_label}</Badge>
          )}
        </span>
      </button>
    </li>
  );

  return (
    <div className="ds-page">
      <PageHeader
        title={t('approvals.title')}
        description={t('approvals.subtitle')}
        primaryAction={
          /* Asking is its own permission, and the queue is where somebody asks. */
          canRequest ? (
            <Button onClick={() => setAsking(true)}>
              <Plus aria-hidden className="me-1 size-4" />
              {t('approvals.raise')}
            </Button>
          ) : undefined
        }
        actions={<LiveBadge isLive={isLive} />}
        filters={
          <div className="flex flex-wrap gap-6">
            <EntityPicker
              label={t('approvals.scope.client')}
              clearLabel={t('actions.clear')}
              value={client}
              onChange={(option) =>
                setClient(option === null ? null : { id: option.id, label: option.label })
              }
              onSearch={setClientTerm}
              options={(clients.data?.data ?? []).map((c) => ({ id: c.id, label: c.name }))}
              isLoading={clients.isFetching}
            />

            <ChoiceGroup label={t('approvals.scope.what')}>
              <div className="flex flex-wrap gap-2">
                <Choice isSelected={!spendOnly} onSelect={() => setSpendOnly(false)}>
                  {t('approvals.scope.everything')}
                </Choice>
                <Choice isSelected={spendOnly} onSelect={() => setSpendOnly(true)}>
                  {t('approvals.scope.spendOnly')}
                </Choice>
              </div>
            </ChoiceGroup>
          </div>
        }
      />

      {queue.isError ? <Alert tone="danger">{t('approvals.loadFailed')}</Alert> : null}

      {queue.isPending || load === undefined || queue.data === undefined ? (
        <Skeleton className="h-32 w-full" />
      ) : (
        <>
          <section
            aria-label={t('approvals.counts')}
            className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
          >
            {[
              { key: 'pending', value: load.pending, tone: 'neutral' as const },
              { key: 'spend', value: load.pending_touching_spend, tone: 'warning' as const },
              /*
               * The figure that justifies the module. Approved and not done is not a process metric, it is a
               * list of things somebody currently believes that may not be true.
               */
              { key: 'outstanding', value: load.outstanding, tone: 'danger' as const },
              { key: 'confirmed', value: load.confirmed, tone: 'neutral' as const },
            ].map((figure) => (
              <Card key={figure.key}>
                <CardContent className="flex flex-col gap-1 pt-5">
                  <span className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
                    {t(`approvals.count.${figure.key}`)}
                  </span>
                  <span className="text-content text-2xl font-semibold">
                    {formatNumber(figure.value, locale)}
                  </span>
                  {figure.value > 0 && figure.tone !== 'neutral' ? (
                    <Badge tone={figure.tone}>{t(`approvals.count.${figure.key}Note`)}</Badge>
                  ) : null}
                </CardContent>
              </Card>
            ))}
          </section>

          <p className="text-content-tertiary text-sm">
            {/*
              Both rates are null until their denominator exists, and both say so in words rather than printing
              nought percent. An approval rate of 100% is also published deliberately: it says the process is a
              formality, which is worth knowing about your own organisation.
            */}
            {load.approval_rate === null
              ? t('approvals.rates.noneDecided')
              : t('approvals.rates.approved', {
                  rate: formatRate(load.approval_rate, locale),
                  decided: formatNumber(load.approved + load.declined, locale),
                })}{' '}
            {load.carried_out_rate === null
              ? t('approvals.rates.noneCarriedOut')
              : t('approvals.rates.carriedOut', {
                  rate: formatRate(load.carried_out_rate, locale),
                })}
          </p>

          <div className="grid gap-6 xl:grid-cols-2">
            <WaitingChart load={load} ages={vocabulary.data?.ages ?? []} />
            <KindBreakdown load={load} kinds={vocabulary.data?.kinds ?? []} />
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            <section aria-label={t('approvals.queue.pending')} className="flex flex-col gap-2">
              <h2 className="text-content-secondary flex items-center justify-between text-xs font-medium tracking-wide uppercase">
                {t('approvals.queue.pending')}
                <span className="text-content-tertiary tabular-nums">
                  {formatNumber(load.pending, locale)}
                </span>
              </h2>
              {queue.data.pending.length === 0 ? (
                <p className="text-content-tertiary text-sm">{t('approvals.queue.nonePending')}</p>
              ) : (
                <ul className="flex flex-col gap-2">{queue.data.pending.map(row)}</ul>
              )}
            </section>

            <section aria-label={t('approvals.queue.outstanding')} className="flex flex-col gap-2">
              <h2 className="text-content-secondary flex items-center justify-between text-xs font-medium tracking-wide uppercase">
                {t('approvals.queue.outstanding')}
                <span className="text-content-tertiary tabular-nums">
                  {formatNumber(load.outstanding, locale)}
                </span>
              </h2>
              {queue.data.outstanding.length === 0 ? (
                <p className="text-content-tertiary text-sm">
                  {/*
                    Worth a sentence rather than an empty space. "Nothing waiting to be carried out" is a good
                    state, and saying it is the difference between a reassurance and a page that failed to load.
                  */}
                  {t('approvals.queue.noneOutstanding')}
                </p>
              ) : (
                <ul className="flex flex-col gap-2">{queue.data.outstanding.map(row)}</ul>
              )}
            </section>
          </div>
        </>
      )}

      <ApprovalDetail
        /* Keyed on the selection, so a reason typed about one request cannot travel to another. */
        key={selected ?? 'none'}
        request={selected === null ? undefined : request.data}
        isLoading={selected !== null && request.isPending}
        canDecide={canDecide}
        canRequest={canRequest}
        onDecide={(outcome, fingerprint, reason) => {
          if (selected !== null) {
            decide.mutate({
              requestId: selected,
              outcome,
              fingerprint,
              ...(reason === undefined ? {} : { reason }),
            });
          }
        }}
        onWithdraw={(reason) => {
          if (selected !== null) {
            withdraw.mutate({ requestId: selected, reason });
          }
        }}
        onEnact={(enactment, note) => {
          if (selected !== null) {
            enact.mutate({
              requestId: selected,
              enactment,
              ...(note === undefined ? {} : { note }),
            });
          }
        }}
        {...(canCarryOut
          ? {
              onCarryOut: () => {
                if (selected !== null) {
                  carryOut.mutate({ requestId: selected });
                }
              },
            }
          : {})}
        outcome={carriedOut}
        isCarryingOut={carryOut.isPending}
        error={error}
      />

      <RequestApprovalForm
        onSearchClients={setClientTerm}
        isSearching={clients.isFetching}
        open={asking}
        onOpenChange={setAsking}
        vocabulary={vocabulary.data}
        clients={clients.data?.data ?? []}
      />
    </div>
  );
}
