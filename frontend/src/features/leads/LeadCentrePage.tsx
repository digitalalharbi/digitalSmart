import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useClientsQuery } from '@/features/clients';
import { useProvidersQuery } from '@/features/integrations';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { MetricCard } from '@/shared/ui/metric-card';
import { Skeleton } from '@/shared/ui/skeleton';
import type { MetricValue } from '@/shared/metrics';
import { BreakdownChart, type BreakdownRow } from './BreakdownChart';
import { JourneyPanel } from './JourneyPanel';
import { CrmReadPanel } from './CrmReadPanel';
import { LeadHistoryPanel } from './LeadHistoryPanel';
import { LeadWorkPanel } from './LeadWorkPanel';
import { MergePanel } from './MergePanel';
import { PipelineChart } from './PipelineChart';
import { PipelineList } from './PipelineList';
import { SignalsPanel } from './SignalsPanel';
import {
  useAddLeadNoteMutation,
  useAssignLeadMutation,
  useChangeStageMutation,
  useJourneyQuery,
  useLeadCentreQuery,
  useLeadDuplicatesQuery,
  useLeadHistoryQuery,
  useLeadOwnersQuery,
  useLeadsQuery,
  useMergeLeadsMutation,
  useSetLeadValueMutation,
  useSyncCrmDealsMutation,
} from './useLeads';
import type { LeadFunnel, LeadStage } from './types';

const PERIODS = [7, 30, 90] as const;

/**
 * The Lead Center.
 *
 * Leads on one side of the page, counted taps on the other, and **no arithmetic between
 * them** — which is the one thing this surface exists to make impossible to do by accident.
 * A WhatsApp tap is not a conversation; a product that added the two columns would report a
 * cost per lead a fraction of the real one.
 *
 * Everything on the page is either a count somebody can go and verify or a rate whose
 * denominator is named beside it. Nothing is modelled, nothing is estimated, and the two
 * figures that would need a model to produce — a lead-to-click rate, and which touch earned
 * the credit — are deliberately absent rather than approximated.
 */
export function LeadCentrePage() {
  const { t } = useTranslation();
  const { can } = useSession();

  const [days, setDays] = useState<(typeof PERIODS)[number]>(30);
  const [clientId, setClientId] = useState<string | null>(null);
  const [selectedLead, setSelectedLead] = useState<string | null>(null);

  const canView = can(PERMISSIONS.leadsView);
  const canManage = can(PERMISSIONS.leadsManage);

  const clients = useClientsQuery({ page: 1, per_page: 100, sort: 'name', direction: 'asc' });
  const scope = useMemo(() => ({ client_id: clientId, days }), [clientId, days]);

  const centre = useLeadCentreQuery(scope, canView);
  const leads = useLeadsQuery({ client_id: clientId, limit: 100 }, canView);
  const journey = useJourneyQuery(selectedLead);
  const duplicates = useLeadDuplicatesQuery(selectedLead, canManage);
  const owners = useLeadOwnersQuery(selectedLead, canManage);
  const history = useLeadHistoryQuery(selectedLead);
  const move = useChangeStageMutation();
  const merge = useMergeLeadsMutation();
  const assign = useAssignLeadMutation();
  const value = useSetLeadValueMutation();
  const addNote = useAddLeadNoteMutation();
  const crmRead = useSyncCrmDealsMutation();

  /*
   * The CRM this workspace has connected, if any. Read from the provider registry rather than hard-coded: the
   * bridge names HubSpot today, and a page that spelled that out would have to be edited for the second one.
   */
  const providers = useProvidersQuery();
  const crm =
    providers.data?.find(
      (provider) => provider.category === 'crm' && provider.connection_id !== null,
    ) ?? null;

  /*
   * One sentence for three mutations, because a reader does not care which call failed — they care that nothing
   * was saved. The server's own message wins where there is one: it names the thing to do about it.
   */
  const workError = [assign.error, value.error, addNote.error].find(
    (candidate) => candidate !== null,
  );

  const data = centre.data;
  const selected = leads.data?.find((lead) => lead.id === selectedLead) ?? null;

  if (!canView) {
    /*
     * Said plainly rather than shown as an empty page. A reader who lands here from a link
     * should learn that the surface exists and is not theirs, not conclude it is broken.
     */
    return <EmptyState title={t('leads.denied.title')} description={t('leads.denied.body')} />;
  }

  const sourceRows: BreakdownRow[] = (data?.by_source ?? []).map((row) => ({
    key: `${row.source_kind}:${row.source_detail ?? ''}`,
    label:
      row.source_detail === null
        ? row.source_kind_label
        : `${row.source_kind_label} — ${row.source_detail}`,
    total: row.total,
    qualified: row.qualified,
    won: row.won,
    winRate: row.win_rate,
    valueMinor: row.won_value_minor,
    currency: row.currency,
    isUnmeasured: !row.is_measured,
  }));

  const campaignRows: BreakdownRow[] = (data?.by_campaign ?? []).map((row) => ({
    key: row.label,
    label: row.is_unattributed ? t('leads.breakdown.unattributed') : row.label,
    total: row.total,
    qualified: row.qualified,
    won: row.won,
    winRate: row.win_rate,
    valueMinor: null,
    currency: null,
    isUnattributed: row.is_unattributed,
    evidenceLabel: row.evidence_label,
    confidence: row.confidence,
  }));

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-4">
        <div>
          <h1 className="text-content text-2xl font-semibold">{t('leads.title')}</h1>
          <p className="text-content-secondary mt-1 max-w-2xl text-sm">{t('leads.subtitle')}</p>
        </div>

        {/*
          One filter row above everything it scopes, so every chart on the page re-renders
          against the same slice. Per-chart filters are how two cards on one screen end up
          disagreeing.
        */}
        <div className="flex flex-wrap items-start gap-6">
          <ChoiceGroup label={t('leads.period.label')}>
            <div className="flex flex-wrap gap-2">
              {PERIODS.map((value) => (
                <Choice key={value} isSelected={days === value} onSelect={() => setDays(value)}>
                  {t('leads.period.days', { count: value })}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>

          <ChoiceGroup label={t('leads.scope.client')}>
            <div className="flex flex-wrap gap-2">
              <Choice isSelected={clientId === null} onSelect={() => setClientId(null)}>
                {t('leads.scope.allClients')}
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
        </div>
      </header>

      {centre.isError ? <Alert tone="danger">{t('leads.loadFailed')}</Alert> : null}

      {centre.isPending ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {[0, 1, 2, 3].map((card) => (
            <Skeleton key={card} className="h-24 w-full" />
          ))}
        </div>
      ) : data === undefined ? null : (
        <>
          <section
            aria-label={t('leads.scorecard')}
            className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
          >
            {scorecard(data.funnel, t).map((value) => (
              <MetricCard key={value.metric} value={value} emphasis="primary" />
            ))}
          </section>

          {/*
            The reason a total is missing, where one is. A dash with no explanation reads as a
            bug; "these leads came in two currencies" is a fact somebody can act on.
          */}
          {data.funnel.currency_count > 1 ? (
            <Alert tone="warning">
              {t('leads.mixedCurrency', { count: data.funnel.currency_count })}
            </Alert>
          ) : null}

          <div className="grid gap-6 xl:grid-cols-2">
            <PipelineChart funnel={data.funnel} windowDays={data.window_days} />
            <SignalsPanel
              signals={data.signals}
              leads={data.funnel.total}
              windowDays={data.window_days}
              note={data.signals_note}
            />
          </div>

          <div className="grid gap-6 xl:grid-cols-2">
            <BreakdownChart
              title={t('leads.bySource.title')}
              subtitle={t('leads.bySource.subtitle')}
              rows={sourceRows}
              windowDays={data.window_days}
              caveats={[t('leads.bySource.caveat')]}
            />
            <BreakdownChart
              title={t('leads.byCampaign.title')}
              subtitle={t('leads.byCampaign.subtitle')}
              rows={campaignRows}
              windowDays={data.window_days}
              caveats={[t('leads.byCampaign.caveat')]}
            />
          </div>
        </>
      )}

      <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
        <PipelineList
          leads={leads.data}
          isLoading={leads.isPending}
          selectedId={selectedLead}
          onSelect={setSelectedLead}
          onMove={(leadId, stage: LeadStage, reason) =>
            move.mutate({ leadId, stage, ...(reason === undefined ? {} : { reason }) })
          }
          canMove={canManage}
          moveError={
            move.error instanceof ApiError && move.error.message !== ''
              ? move.error.message
              : move.isError
                ? t('leads.list.moveFailed')
                : null
          }
        />

        <div className="flex flex-col gap-6">
          <JourneyPanel
            journey={selectedLead === null ? undefined : journey.data}
            isLoading={selectedLead !== null && journey.isPending}
            leadName={selected?.name ?? null}
          />

          {/*
            Beside the journey rather than in the pipeline row, because that is where somebody is already
            looking at who this person is. The panel is absent when there is nothing matching on an exact
            contact point — a "no duplicates found" on every lead teaches a reader to ignore the one time it
            has something to say.
          */}
          {/*
            Beside the journey, where somebody is already looking at who this person is. Absent for a reader who
            may not manage leads: the controls would be refused by the server anyway, and showing them would be
            an invitation to try.
          */}
          <LeadWorkPanel
            /*
             * Keyed by the lead, so selecting a different one remounts the panel and nothing typed for the last
             * one carries over. A half-written note arriving on somebody else's record is the kind of mistake
             * that gets saved.
             */
            key={selectedLead ?? 'none'}
            lead={selected}
            owners={selectedLead === null ? undefined : owners.data}
            onAssign={(ownerId) =>
              selectedLead === null ? undefined : assign.mutate({ leadId: selectedLead, ownerId })
            }
            onSetValue={(valueMinor, currency) =>
              selectedLead === null
                ? undefined
                : value.mutate({ leadId: selectedLead, valueMinor, currency })
            }
            onAddNote={(note) =>
              selectedLead === null ? undefined : addNote.mutate({ leadId: selectedLead, note })
            }
            canManage={canManage}
            isBusy={assign.isPending || value.isPending || addNote.isPending}
            error={
              workError instanceof ApiError && workError.message !== ''
                ? workError.message
                : workError === undefined
                  ? null
                  : t('leads.work.failed')
            }
          />

          {selectedLead === null ? null : (
            <LeadHistoryPanel entries={history.data} isLoading={history.isPending} />
          )}

          {/*
            A read, offered where CRM-sourced leads appear. The client comes from the filter at the top of the
            page, because a CRM portal belongs to one company and nothing here infers which client's deals those
            are — see `SyncCrmDeals`.
          */}
          <CrmReadPanel
            crmLabel={crm?.label ?? null}
            clientId={clientId}
            clientName={clients.data?.data.find((client) => client.id === clientId)?.name ?? null}
            onRead={() => (clientId === null ? undefined : crmRead.mutate(clientId))}
            isReading={crmRead.isPending}
            result={crmRead.data ?? null}
            error={
              crmRead.error instanceof ApiError && crmRead.error.message !== ''
                ? crmRead.error.message
                : crmRead.isError
                  ? t('leads.crm.failed')
                  : null
            }
            canRead={canManage}
          />

          <MergePanel
            lead={selected}
            duplicates={selectedLead === null ? undefined : duplicates.data}
            /*
             * `canManage` as well as the selection, because a disabled query reports `isPending` forever — so
             * without it a reader who may not merge would sit in front of a loading panel that never resolves,
             * for an operation that is not theirs.
             */
            isLoading={canManage && selectedLead !== null && duplicates.isPending}
            onMerge={(otherLeadId, stage, note) =>
              selectedLead === null
                ? undefined
                : merge.mutate({
                    leadId: selectedLead,
                    otherLeadId,
                    stage,
                    ...(note === undefined ? {} : { note }),
                  })
            }
            isMerging={merge.isPending}
            canMerge={canManage}
            error={
              merge.error instanceof ApiError && merge.error.message !== ''
                ? merge.error.message
                : merge.isError
                  ? t('leads.merge.failed')
                  : null
            }
          />
        </div>
      </div>
    </div>
  );
}

/**
 * The four figures the page leads with.
 *
 * Built as `MetricValue`s so the shared card formats them, which is what carries the two
 * rules that matter: a null renders as absent **with its reason** rather than as zero, and
 * every figure says where it came from. Reimplementing either here would be reimplementing
 * them differently.
 */
function scorecard(funnel: LeadFunnel, t: TFunction): MetricValue[] {
  const base = {
    /*
     * `manual` is the honest label for a pipeline. These counts are what people recorded and
     * moved by hand — not a platform's measurement — and a reader comparing them with an ad
     * platform's conversion count needs to know they are different kinds of fact.
     */
    source: 'manual' as const,
    source_label: t('leads.source.label'),
    requires_label: true,
    comparison_value: null,
    change_ratio: null,
    change_is_good: null,
    lower_is_better: false,
    currency: null,
    is_minor_units: false,
    unavailable_reason: null,
  };

  return [
    {
      ...base,
      metric: 'leads',
      label: t('leads.metric.total'),
      value: funnel.total,
      format: 'count',
      precision: 0,
    },
    {
      ...base,
      metric: 'win_rate',
      label: t('leads.metric.winRate'),
      value: funnel.win_rate,
      format: 'percentage',
      precision: 1,
      // Named, because a win rate over all leads and one over closed leads are different numbers.
      unavailable_reason: funnel.win_rate === null ? t('leads.metric.noClosed') : null,
    },
    {
      ...base,
      metric: 'contact_rate',
      label: t('leads.metric.contactRate'),
      value: funnel.contact_rate,
      format: 'percentage',
      precision: 1,
      unavailable_reason: funnel.contact_rate === null ? t('leads.metric.noLeads') : null,
    },
    {
      ...base,
      metric: 'average_won_value',
      label: t('leads.metric.averageWon'),
      value: funnel.average_won_value_minor,
      format: 'currency',
      precision: 2,
      is_minor_units: true,
      currency: funnel.currency,
      /*
       * Two different absences, said differently. No wins is a young pipeline; several
       * currencies is a figure that cannot be added at all.
       */
      unavailable_reason:
        funnel.average_won_value_minor !== null
          ? null
          : funnel.currency_count > 1
            ? t('leads.metric.mixedCurrency')
            : t('leads.metric.noWonValue'),
    },
  ];
}
