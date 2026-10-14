import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Ban, Info } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useClientsQuery } from '@/features/clients';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import { PageHeader } from '@/shared/ui/page-header';
import { AnswerView } from './AnswerView';
import {
  useAnalysisQuery,
  useAskMutation,
  useAssistantVocabularyQuery,
  useFindingQuery,
  useReportProposalQuery,
} from './useAssistant';
import type { AssistantModeValue } from './types';

/** Long enough for a trend, short enough to be about now. The server clamps to the same range. */
const PERIODS = [7, 30, 90] as const;

/**
 * The assistant.
 *
 * Four modes, one client, one period. The client is **required** rather than defaulted to all of them: a cost per
 * click averaged across unrelated accounts is a number with no owner, and an answer that silently did that would
 * be the most confident wrong figure this product could produce.
 *
 * The page leads with what the module does and does not do. That is not boilerplate: an assistant is the one
 * surface where a reader's default assumption — that a model worked it out — is exactly wrong, and the correction
 * has to arrive before the answer rather than after it.
 */
export function AssistantPage() {
  const { t } = useTranslation();
  const { can } = useSession();

  const canUse = can(PERMISSIONS.analyticsView);
  const canReport = can(PERMISSIONS.reportsManage);

  const [mode, setMode] = useState<AssistantModeValue>('analyse');
  const [clientId, setClientId] = useState<string | null>(null);
  const [days, setDays] = useState<number>(30);
  const [question, setQuestion] = useState('');
  const [investigating, setInvestigating] = useState<string | null>(null);

  const clients = useClientsQuery({ per_page: 100 });
  const vocabulary = useAssistantVocabularyQuery(canUse);

  const analysis = useAnalysisQuery(clientId, days, canUse && mode === 'analyse');
  const finding = useFindingQuery(clientId, days, mode === 'investigate' ? investigating : null);
  const proposal = useReportProposalQuery(clientId, days, canUse && canReport && mode === 'report');
  const ask = useAskMutation();

  const error = useMemo(() => {
    for (const candidate of [analysis.error, finding.error, proposal.error, ask.error]) {
      if (candidate instanceof ApiError) {
        return candidate.message;
      }
    }

    return null;
  }, [analysis.error, finding.error, proposal.error, ask.error]);

  if (!canUse) {
    return (
      <EmptyState title={t('assistant.denied.title')} description={t('assistant.denied.body')} />
    );
  }

  const modes: AssistantModeValue[] = canReport
    ? ['analyse', 'ask', 'investigate', 'report']
    : ['analyse', 'ask', 'investigate'];

  return (
    <div className="ds-page">
      <PageHeader title={t('assistant.title')} description={t('assistant.subtitle')} />

      {/*
        Before the answer, not after it. This is the one surface where a reader's default assumption — that a
        model worked the figures out — is exactly wrong.
      */}
      <Alert tone="info" className="flex items-start gap-2">
        <Info aria-hidden className="mt-0.5 size-4 shrink-0" />
        <span className="flex flex-col gap-1">
          <span className="font-medium">{t('assistant.honesty.title')}</span>
          <span>{t('assistant.honesty.computed')}</span>
          <span>
            {t('assistant.honesty.floor', {
              floor: vocabulary.data?.denominator_floor ?? 30,
            })}
          </span>
          <span>{t('assistant.honesty.enacts')}</span>
        </span>
      </Alert>

      {error === null ? null : <Alert tone="danger">{error}</Alert>}

      <div className="flex flex-col gap-4">
        <ChoiceGroup label={t('assistant.client')}>
          <div className="flex flex-wrap gap-2">
            {(clients.data?.data ?? []).map((client) => (
              <Choice
                key={client.id}
                isSelected={clientId === client.id}
                onSelect={() => {
                  setClientId(client.id);
                  setInvestigating(null);
                  ask.reset();
                }}
              >
                {client.name}
              </Choice>
            ))}
          </div>
        </ChoiceGroup>

        <ChoiceGroup label={t('assistant.period')}>
          <div className="flex flex-wrap gap-2">
            {PERIODS.map((value) => (
              <Choice key={value} isSelected={days === value} onSelect={() => setDays(value)}>
                {t('assistant.days', { count: value })}
              </Choice>
            ))}
          </div>
        </ChoiceGroup>
      </div>

      {clientId === null ? (
        <EmptyState
          title={t('assistant.pickClient.title')}
          description={t('assistant.pickClient.body')}
        />
      ) : (
        <>
          <nav aria-label={t('assistant.title')}>
            <div className="flex flex-wrap gap-2">
              {modes.map((value) => (
                <Choice key={value} isSelected={mode === value} onSelect={() => setMode(value)}>
                  {t(`assistant.modes.${value}`)}
                </Choice>
              ))}
            </div>
          </nav>

          {mode === 'ask' ? (
            <section aria-label={t('assistant.ask.label')} className="flex flex-col gap-4">
              <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(event) => {
                  event.preventDefault();

                  if (question.trim().length < 3) {
                    return;
                  }

                  ask.mutate({ clientId, days, question: question.trim() });
                }}
              >
                <Field label={t('assistant.ask.label')} className="min-w-64 flex-1">
                  {(props) => (
                    <Input
                      {...props}
                      value={question}
                      placeholder={t('assistant.ask.placeholder')}
                      onChange={(event) => setQuestion(event.target.value)}
                    />
                  )}
                </Field>
                <Button type="submit" disabled={question.trim().length < 3 || ask.isPending}>
                  {t('assistant.ask.submit')}
                </Button>
              </form>

              {/* A closed list is only usable if it is published. */}
              <section aria-label={t('assistant.ask.understands')} className="flex flex-col gap-1">
                <h2 className="text-content-tertiary text-xs font-medium">
                  {t('assistant.ask.understands')}
                </h2>
                <ul className="flex flex-wrap gap-2">
                  {(vocabulary.data?.questions ?? []).map((entry) => (
                    <li key={entry.intent}>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                          setQuestion(entry.example);
                          ask.mutate({ clientId, days, question: entry.example });
                        }}
                      >
                        {entry.example}
                      </Button>
                    </li>
                  ))}
                </ul>
              </section>

              {ask.isPending ? (
                <Skeleton className="h-48 w-full" />
              ) : ask.data === undefined ? null : (
                <AnswerView answer={ask.data} />
              )}
            </section>
          ) : mode === 'investigate' ? (
            <section aria-label={t('assistant.modes.investigate')} className="flex flex-col gap-4">
              {investigating === null ? (
                <AnalyseTab
                  clientId={clientId}
                  days={days}
                  onInvestigate={(code) => setInvestigating(code)}
                />
              ) : finding.isPending ? (
                <Skeleton className="h-64 w-full" />
              ) : finding.data === undefined ? null : (
                <>
                  <span>
                    <Button variant="ghost" size="sm" onClick={() => setInvestigating(null)}>
                      {t('assistant.modes.analyse')}
                    </Button>
                  </span>
                  <AnswerView answer={finding.data} />
                </>
              )}
            </section>
          ) : mode === 'report' ? (
            proposal.isPending ? (
              <Skeleton className="h-64 w-full" />
            ) : proposal.data === undefined ? null : (
              <section aria-label={t('assistant.report.title')} className="flex flex-col gap-4">
                <div className="bg-surface border-line-subtle flex flex-col gap-2 rounded-xl border p-4">
                  <h2 className="text-content text-sm font-semibold">
                    {t('assistant.report.title')}
                  </h2>
                  <p className="text-content-secondary text-sm">{t('assistant.report.body')}</p>
                  <p className="text-content-tertiary text-xs">{t('assistant.report.audience')}</p>
                  <p className="text-content text-sm font-medium">{proposal.data.proposal.name}</p>
                  <ul className="text-content-secondary flex flex-col gap-1 text-sm">
                    {proposal.data.proposal.sections.map((section, index) => (
                      <li key={`${section.kind}-${index}`}>
                        {section.kind}: {section.metrics.join(', ')}
                        {section.dimension === null ? '' : ` · ${section.dimension}`}
                      </li>
                    ))}
                  </ul>
                </div>

                <AnswerView answer={proposal.data.analysis} />
              </section>
            )
          ) : (
            <AnalyseTab
              clientId={clientId}
              days={days}
              onInvestigate={(code) => {
                setInvestigating(code);
                setMode('investigate');
              }}
            />
          )}

          {/* Published rather than offered as a tab that produces nothing. */}
          {(vocabulary.data?.deferred ?? []).length === 0 ? null : (
            <section aria-label={t('assistant.deferred.title')} className="flex flex-col gap-2">
              <h2 className="text-content flex items-center gap-1.5 text-sm font-semibold">
                <Ban aria-hidden className="size-3.5" />
                {t('assistant.deferred.title')}
              </h2>
              <p className="text-content-tertiary text-sm">{t('assistant.deferred.body')}</p>
              <ul className="flex flex-col gap-2">
                {(vocabulary.data?.deferred ?? []).map((entry) => (
                  <li key={entry.mode} className="text-sm">
                    <span className="text-content font-medium">{entry.label}</span>
                    <span className="text-content-secondary"> — {entry.because}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </>
      )}
    </div>
  );
}

/** The analysis, shared by the Analyse tab and by Investigate before a finding is chosen. */
function AnalyseTab({
  clientId,
  days,
  onInvestigate,
}: {
  clientId: string;
  days: number;
  onInvestigate: (code: string) => void;
}) {
  const { t } = useTranslation();
  const analysis = useAnalysisQuery(clientId, days);

  if (analysis.isPending) {
    return <Skeleton className="h-64 w-full" />;
  }

  if (analysis.data === undefined) {
    return <Alert tone="danger">{t('assistant.loadFailed')}</Alert>;
  }

  return <AnswerView answer={analysis.data} onInvestigate={onInvestigate} />;
}
