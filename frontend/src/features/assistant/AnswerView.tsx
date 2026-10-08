import { useTranslation } from 'react-i18next';
import { Bot, ShieldAlert, Sparkles } from 'lucide-react';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { EmptyState } from '@/shared/ui/empty-state';
import { SourceBadge } from '@/shared/ui/source-badge';
import type { MetricSourceValue } from '@/shared/metrics';
import { FindingCard } from './FindingCard';
import type { AssistantAnswer } from './types';

/**
 * An answer, with everything a reader needs to disbelieve it.
 *
 * The header is the honest part and carries four things a reader would otherwise have to infer: the period, the
 * baseline every change figure is measured against, the weakest source behind any of it, and **who wrote the
 * prose**. The last is the one people forget: a paragraph composed by a language model and a paragraph assembled
 * by the product are both legitimate, and presenting one as the other is not.
 *
 * When a model's answer was refused, that is shown rather than swallowed. A guard nobody can see working is a
 * guard nobody will maintain.
 */
export function AnswerView({
  answer,
  onInvestigate,
}: {
  answer: AssistantAnswer;
  onInvestigate?: (code: string) => void;
}) {
  const { t } = useTranslation();

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-col gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <SourceBadge source={answer.source as MetricSourceValue} />
          <Badge tone={answer.needs_attention > 0 ? 'warning' : 'neutral'}>
            {answer.needs_attention === 0
              ? t('assistant.answer.nothingUrgent')
              : t('assistant.answer.needsAttention', { count: answer.needs_attention })}
          </Badge>
          {/*
            Its own badge. A list where some questions went unanswered reads, to somebody skimming, exactly like
            a list where they were answered favourably.
          */}
          {answer.unanswered > 0 ? (
            <Badge tone="accent">
              {t('assistant.answer.unanswered', { count: answer.unanswered })}
            </Badge>
          ) : null}
        </div>

        <p className="text-content text-sm font-medium">
          {t('assistant.answer.period', {
            from: answer.period.from,
            until: answer.period.until,
            days: answer.period.days,
          })}
        </p>
        <p className="text-content-secondary text-sm">
          {t('assistant.answer.comparedWith', {
            from: answer.comparison_period.from,
            until: answer.comparison_period.until,
          })}
        </p>

        {answer.intent === null || answer.question === null ? null : (
          <p className="text-content-tertiary text-xs">
            {t('assistant.ask.intent', { label: answer.intent })}
          </p>
        )}

        {/*
          Named, not counted. A reader with fewer permissions must be able to tell that a question went unasked
          rather than that it was asked and found nothing.
        */}
        {answer.skipped.length === 0 ? null : (
          <p className="text-content-tertiary text-xs">
            {t('assistant.answer.skipped', { codes: answer.skipped.join(', ') })}
          </p>
        )}
      </header>

      {answer.narrative.model_rejected ? (
        <Alert tone="warning" className="flex flex-col gap-1">
          <span className="flex items-center gap-1.5 font-medium">
            <ShieldAlert aria-hidden className="size-4" />
            {t('assistant.narrative.rejectedTitle')}
          </span>
          <span>
            {t('assistant.narrative.rejected', {
              reason: answer.narrative.rejection_reason ?? '',
            })}
          </span>
        </Alert>
      ) : null}

      <section aria-label={t('assistant.title')} className="flex flex-col gap-2">
        <p className="text-content-tertiary flex items-center gap-1.5 text-xs">
          {answer.narrative.written_by === 'model' ? (
            <>
              <Bot aria-hidden className="size-3.5" />
              {t('assistant.narrative.byModel', { model: answer.narrative.model ?? '' })}
            </>
          ) : (
            <>
              <Sparkles aria-hidden className="size-3.5" />
              {t('assistant.narrative.byProduct')}
            </>
          )}
        </p>
        <div className="text-content flex flex-col gap-3 text-sm leading-relaxed">
          {answer.narrative.text.split('\n\n').map((paragraph, index) => (
            <p key={index}>{paragraph}</p>
          ))}
        </div>
      </section>

      {answer.findings.length === 0 ? (
        <EmptyState
          title={t('assistant.answer.emptyTitle')}
          description={t('assistant.answer.emptyBody')}
        />
      ) : (
        <div className="flex flex-col gap-3">
          {answer.findings.map((finding) => (
            <FindingCard
              key={finding.code}
              finding={finding}
              {...(onInvestigate === undefined
                ? {}
                : { onInvestigate: () => onInvestigate(finding.code) })}
            />
          ))}
        </div>
      )}
    </div>
  );
}
