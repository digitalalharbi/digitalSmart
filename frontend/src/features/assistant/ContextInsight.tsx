import { Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Badge } from '@/shared/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { Skeleton } from '@/shared/ui/skeleton';
import { useAnalysisQuery } from './useAssistant';
import type { AssistantFinding } from './types';

const TONE = { firm: 'success', tentative: 'warning', indicative: 'neutral' } as const;

/**
 * What the assistant noticed about this client, in the place you are already looking.
 *
 * The same analysis the assistant page runs — never a second one — cut down to the two things most worth a look:
 * the issue, the figures behind it, what (if anything) it recommends, and how sure it is. A recommendation appears
 * only when the server found the evidence firm enough to give one; otherwise the card says there is none, because
 * silence would read as "nothing to do" and a hedged guess would read as advice.
 *
 * It reads and suggests. Nothing here changes a budget, a status or a target.
 */
export function ContextInsight({
  clientId,
  clientName,
}: {
  clientId: string | null;
  clientName?: string | null;
}) {
  const { t, i18n } = useTranslation();
  const analysis = useAnalysisQuery(clientId, 28, clientId !== null);

  if (clientId === null || analysis.isError) {
    return null;
  }

  const findings = (analysis.data?.findings ?? [])
    .filter((finding) => finding.needs_attention)
    .slice(0, 2);

  if (analysis.data !== undefined && findings.length === 0) {
    return null;
  }

  const number = (value: number) =>
    new Intl.NumberFormat(i18n.language, { maximumFractionDigits: 2 }).format(value);

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-3">
        <CardTitle className="flex items-center gap-2">
          <Sparkles aria-hidden className="text-content-brand size-4" />
          {t('contextInsight.title')}
        </CardTitle>
        <Link
          to={
            clientName === undefined || clientName === null
              ? '/ai-assistant'
              : `/ai-assistant?client=${encodeURIComponent(`${clientId}~${clientName}`)}`
          }
          className="text-content-brand text-xs font-medium hover:underline"
        >
          {t('contextInsight.open')}
        </Link>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {analysis.isPending ? (
          <Skeleton className="h-20" />
        ) : (
          findings.map((finding) => (
            <Insight key={finding.code} finding={finding} number={number} />
          ))
        )}
      </CardContent>
    </Card>
  );
}

function Insight({
  finding,
  number,
}: {
  finding: AssistantFinding;
  number: (value: number) => string;
}) {
  const { t } = useTranslation();
  const evidence = finding.evidence.filter((item) => item.value !== null).slice(0, 2);

  return (
    <article className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-1.5">
        <Badge tone={finding.severity === 'serious' ? 'danger' : 'warning'}>
          {finding.severity_label}
        </Badge>
        <Badge tone={TONE[finding.confidence]}>
          {t('assistant.finding.confidence', { level: finding.confidence_label })}
        </Badge>
      </div>
      <h3 className="text-content text-sm font-semibold" dir="auto">
        {finding.headline}
      </h3>
      {evidence.length === 0 ? null : (
        <ul className="text-content-secondary flex flex-col gap-0.5 text-xs">
          {evidence.map((item, index) => (
            <li key={`${item.metric}-${String(index)}`}>
              {item.label}:{' '}
              <span className="text-content tabular-nums">{number(item.value ?? 0)}</span>
              {item.compared_with === null
                ? null
                : ` (${t('assistant.finding.was', { value: number(item.compared_with) })})`}
            </li>
          ))}
        </ul>
      )}
      <p className="text-content-secondary text-sm" dir="auto">
        {finding.recommendation ?? t('assistant.finding.noRecommendation')}
      </p>
    </article>
  );
}
