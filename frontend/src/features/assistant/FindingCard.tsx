import { useTranslation } from 'react-i18next';
import { Lightbulb, TriangleAlert } from 'lucide-react';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { SourceBadge } from '@/shared/ui/source-badge';
import type { MetricSourceValue } from '@/shared/metrics';
import type { AssistantFinding, FindingSeverityValue } from './types';

const TONE: Record<FindingSeverityValue, 'danger' | 'warning' | 'neutral' | 'accent'> = {
  serious: 'danger',
  warning: 'warning',
  context: 'neutral',
  insufficient: 'accent',
};

/**
 * One finding, with its working shown.
 *
 * The evidence is **not** behind a disclosure. Every number in the headline and the detail comes from the list
 * below it, and a reader who has to click to find that out is a reader who will not: the whole claim of this
 * module is that its figures are checkable, and a claim nobody can check in passing is a claim nobody checks.
 *
 * A finding with no recommendation says so, rather than leaving a blank. "No recommendation, the evidence is not
 * firm enough" is the useful version of silence — and it is most of the findings here, deliberately.
 */
export function FindingCard({
  finding,
  onInvestigate,
}: {
  finding: AssistantFinding;
  onInvestigate?: () => void;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;

  const number = (value: number): string =>
    new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(value);

  return (
    <article className="bg-surface border-line-subtle flex flex-col gap-3 rounded-2xl border p-4">
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <span className="flex flex-wrap items-center gap-1.5">
            <Badge tone={TONE[finding.severity]}>{finding.severity_label}</Badge>
            <span className="text-content-tertiary text-xs">{finding.subject}</span>
            <SourceBadge source={finding.source as MetricSourceValue} />
          </span>
          <h3 className="text-content mt-2 text-sm font-semibold">{finding.headline}</h3>
          <p className="text-content-secondary mt-1 text-sm">{finding.detail}</p>
        </div>
      </header>

      {/* Not behind a disclosure. See the note at the top of this file. */}
      <section aria-label={t('assistant.finding.evidence')} className="flex flex-col gap-1.5">
        <h4 className="text-content-tertiary text-xs font-medium">
          {t('assistant.finding.evidence')}
        </h4>
        <ul className="flex flex-col gap-1">
          {finding.evidence.map((item, index) => (
            <li key={`${item.metric}-${index}`} className="text-sm">
              <span className="text-content-secondary">{item.label}: </span>
              {item.value === null ? (
                <span className="text-content-tertiary">
                  {t('assistant.finding.unavailable')}
                  {item.reason === null ? null : ` — ${item.reason}`}
                </span>
              ) : (
                <>
                  <span className="text-content tabular-nums">{number(item.value)}</span>
                  {item.compared_with === null ? null : (
                    <span className="text-content-tertiary">
                      {' '}
                      ({t('assistant.finding.was', { value: number(item.compared_with) })})
                    </span>
                  )}
                </>
              )}
              <span className="text-content-tertiary text-xs">
                {' · '}
                {item.period}
                {' · '}
                {t('assistant.finding.readFrom', { surface: item.read_from })}
                {' · '}
                {item.source_label}
              </span>
            </li>
          ))}
        </ul>
      </section>

      <footer className="flex flex-col gap-2">
        <p className="text-content-tertiary text-xs">
          {t('assistant.finding.confidence', { level: finding.confidence_label })}
        </p>

        {finding.recommendation === null ? (
          /*
            Said rather than left blank. Most findings carry no recommendation, and a reader who is not told why
            assumes the module had nothing to offer rather than that the evidence was too thin.
          */
          <p className="text-content-tertiary flex items-start gap-1.5 text-xs">
            <TriangleAlert aria-hidden className="mt-0.5 size-3 shrink-0" />
            {t('assistant.finding.noRecommendation')}
          </p>
        ) : (
          <p className="text-content-secondary flex items-start gap-1.5 text-sm">
            <Lightbulb aria-hidden className="mt-0.5 size-3.5 shrink-0" />
            <span>
              <span className="text-content-tertiary text-xs">
                {t('assistant.finding.recommendation')}:{' '}
              </span>
              {finding.recommendation}
            </span>
          </p>
        )}

        {onInvestigate === undefined ? null : (
          <span>
            <Button variant="ghost" size="sm" onClick={onInvestigate}>
              {t('assistant.finding.investigate')}
            </Button>
          </span>
        )}
      </footer>
    </article>
  );
}
