import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Lightbulb } from 'lucide-react';
import { cn } from '@/shared/lib/cn';

/**
 * A short observation about what is on screen.
 *
 * **It is an observation, not a recommendation.** "Google Ads is 62% of spend and 41% of conversions" is a
 * reading of the data in front of the user; "move budget to Meta" is advice this product is not in a
 * position to give, and a client being billed on these numbers deserves the difference.
 *
 * One or two sentences. Anything longer is an analysis, and an analysis belongs in a report.
 *
 * The caller computes the sentence from data it already has. Nothing here generates text: a card that
 * invents a claim the page cannot substantiate is worse than no card.
 */
export function InsightCard({
  children,
  action,
  className,
}: {
  children: ReactNode;
  action?: ReactNode | undefined;
  className?: string | undefined;
}) {
  const { t } = useTranslation();

  return (
    <aside
      aria-label={t('insight.label')}
      className={cn(
        'bg-accent-subtle/60 border-line-subtle flex flex-wrap items-start gap-3 rounded-2xl border p-4',
        className,
      )}
    >
      <Lightbulb aria-hidden className="text-content-brand mt-0.5 size-4 shrink-0" />
      <p className="text-content-secondary min-w-0 flex-1 text-sm leading-relaxed">{children}</p>
      {action === undefined ? null : <div className="shrink-0">{action}</div>}
    </aside>
  );
}
