import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useClientsQuery } from '@/features/clients';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { AttributedCampaigns } from './AttributedCampaigns';
import { CoverageMeter } from './CoverageMeter';
import { ModelComparison } from './ModelComparison';
import { ModelSelector } from './ModelSelector';
import { windowFor } from './money';
import {
  useAttributionModelsQuery,
  useAttributionQuery,
  useComparisonQuery,
} from './useAttribution';
import type { AttributionModelValue } from './types';

/**
 * The revenue authorities a reader may attribute.
 *
 * Only the ones that record an order. An ad platform's own conversion count is already that platform's
 * attribution of its own spend, and running a second model over it would be attributing an attribution.
 */
const SOURCES = ['salla', 'zid', 'crm', 'manual'] as const;

const PERIODS = [7, 30, 90] as const;

/**
 * Attribution.
 *
 * The page refuses to make two decisions on the reader's behalf, and says why: **which model** credits
 * the campaign, and **which authority** counts the revenue. Each changes the answer, and the two
 * compound — "last-recorded revenue from Salla" and "first-recorded revenue from a CRM" are different
 * claims about the same month and neither is wrong.
 *
 * It leads with coverage rather than with the campaign rows. An attributed figure read as a total is
 * the specific way this page could mislead, and the number that prevents it is the share of the period
 * the figure accounts for.
 */
export function AttributionPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const canView = can(PERMISSIONS.analyticsView);

  const [model, setModel] = useState<AttributionModelValue>('last_recorded');
  const [source, setSource] = useState<(typeof SOURCES)[number]>('salla');
  const [days, setDays] = useState<(typeof PERIODS)[number]>(30);
  const [clientId, setClientId] = useState<string | null>(null);
  const [lookback, setLookback] = useState(30);

  const clients = useClientsQuery({ page: 1, per_page: 100, sort: 'name', direction: 'asc' });
  const period = useMemo(() => windowFor(days), [days]);

  const query = useMemo(
    () => ({
      model,
      revenue_source: source,
      from: period.from,
      until: period.until,
      client_id: clientId,
      lookback_days: lookback,
    }),
    [model, source, period, clientId, lookback],
  );

  const models = useAttributionModelsQuery(canView);
  const answer = useAttributionQuery(query, canView);
  const comparison = useComparisonQuery(
    {
      revenue_source: source,
      from: period.from,
      until: period.until,
      client_id: clientId,
      lookback_days: lookback,
    },
    canView,
  );

  if (!canView) {
    return (
      <EmptyState
        title={t('attribution.denied.title')}
        description={t('attribution.denied.body')}
      />
    );
  }

  const refusal =
    answer.error instanceof ApiError && answer.error.status === 422 ? answer.error.message : null;

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-4">
        <div>
          <h1 className="text-content text-2xl font-semibold">{t('attribution.title')}</h1>
          <p className="text-content-secondary mt-1 max-w-2xl text-sm">
            {t('attribution.subtitle')}
          </p>
        </div>

        {/*
          One filter row above everything it scopes, so the single-model view and the comparison always
          describe the same slice. Two charts on one page disagreeing about the period is the fastest
          way to make a reader distrust both.
        */}
        <div className="flex flex-wrap items-start gap-6">
          <ChoiceGroup label={t('attribution.source.label')} hint={t('attribution.source.hint')}>
            <div className="flex flex-wrap gap-2">
              {SOURCES.map((value) => (
                <Choice key={value} isSelected={source === value} onSelect={() => setSource(value)}>
                  {t(`attribution.sources.${value}`)}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>

          <ChoiceGroup label={t('attribution.period.label')}>
            <div className="flex flex-wrap gap-2">
              {PERIODS.map((value) => (
                <Choice key={value} isSelected={days === value} onSelect={() => setDays(value)}>
                  {t('attribution.period.days', { count: value })}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>

          <ChoiceGroup label={t('attribution.scope.client')}>
            <div className="flex flex-wrap gap-2">
              <Choice isSelected={clientId === null} onSelect={() => setClientId(null)}>
                {t('attribution.scope.allClients')}
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

          {/*
            Only shown for a model that reads a person's history, because for the others it changes
            nothing — and a control that silently does nothing teaches a reader to distrust the others.
          */}
          {models.data?.find((one) => one.model === model)?.needs_person === true ? (
            <ChoiceGroup
              label={t('attribution.lookback.label')}
              hint={t('attribution.lookback.hint')}
            >
              <div className="flex flex-wrap gap-2">
                {[7, 30, 90].map((value) => (
                  <Choice
                    key={value}
                    isSelected={lookback === value}
                    onSelect={() => setLookback(value)}
                  >
                    {t('attribution.period.days', { count: value })}
                  </Choice>
                ))}
              </div>
            </ChoiceGroup>
          ) : null}
        </div>
      </header>

      {models.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : (
        <ModelSelector models={models.data ?? []} selected={model} onSelect={setModel} />
      )}

      {/*
        A model this product cannot compute answers 422 with the reason. Rendered as the server's own
        words: it names what is missing and what would unblock it, which a generic failure would not.
      */}
      {refusal === null ? null : <Alert tone="warning">{refusal}</Alert>}

      {answer.isError && refusal === null ? (
        <Alert tone="danger">{t('attribution.loadFailed')}</Alert>
      ) : null}

      {answer.isPending ? (
        <div className="flex flex-col gap-6">
          <Skeleton className="h-36 w-full" />
          <Skeleton className="h-72 w-full" />
        </div>
      ) : answer.data === undefined ? null : (
        <>
          <CoverageMeter coverage={answer.data.coverage} />
          <AttributedCampaigns answer={answer.data} />
        </>
      )}

      {comparison.isPending ? (
        <Skeleton className="h-72 w-full" />
      ) : comparison.data === undefined ? null : (
        <ModelComparison comparison={comparison.data} models={models.data ?? []} />
      )}
    </div>
  );
}
