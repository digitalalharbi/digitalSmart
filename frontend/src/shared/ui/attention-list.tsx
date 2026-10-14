import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { ArrowRight, UserRound } from 'lucide-react';
import { cn } from '@/shared/lib/cn';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { EmptyState } from '@/shared/ui/empty-state';
import { sortAttention, type AttentionItem } from '@/shared/ui/attention.model';
import { HealthDot } from '@/shared/ui/health';
import { Skeleton } from '@/shared/ui/skeleton';

/**
 * The one list of things waiting on a person.
 *
 * Three rules hold it together:
 *
 * **Worst first, then oldest.** Severity is the primary sort because a critical item cannot wait behind a
 * watch item; age breaks ties because the thing that has been ignored longest is the thing most likely to
 * have been forgotten rather than deferred.
 *
 * **One action per item.** A card with three buttons is a decision, and this list exists to remove decisions.
 *
 * **No diagnostics.** "Conversion tag has not fired since Tuesday" belongs here; "GTM container GTM-XXXX
 * returned 404 on /checkout" belongs in the detail view the action opens. A card that reads like a log line
 * gets skipped by the person who most needs to act on it.
 */
export function AttentionList({
  items,
  isPending = false,
  limit,
  className,
}: {
  items: AttentionItem[];
  isPending?: boolean | undefined;
  /** Show at most this many. The caller says what "see all" means; this component does not invent a route. */
  limit?: number | undefined;
  className?: string | undefined;
}) {
  const { t, i18n } = useTranslation();

  if (isPending) {
    return (
      <div
        className={cn('flex flex-col gap-2', className)}
        role="status"
        aria-label={t('attention.loading')}
      >
        <Skeleton className="h-20 w-full" />
        <Skeleton className="h-20 w-full" />
      </div>
    );
  }

  if (items.length === 0) {
    return (
      <EmptyState title={t('attention.empty.title')} description={t('attention.empty.body')} />
    );
  }

  const ordered = sortAttention(items);
  const shown = limit === undefined ? ordered : ordered.slice(0, limit);

  return (
    <ul className={cn('flex flex-col gap-2', className)} aria-label={t('attention.title')}>
      {shown.map((item) => (
        <li key={item.id}>
          <article className="bg-surface border-line-subtle flex flex-wrap items-start justify-between gap-3 rounded-2xl border p-4 shadow-xs">
            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
              <span className="flex items-center gap-2">
                <HealthDot state={item.severity} />
                <span className="ds-overline text-content-tertiary">{item.entity.kind}</span>
                <span className="text-content-secondary truncate text-xs">{item.entity.label}</span>
              </span>

              <p className="text-content text-sm font-medium">{item.message}</p>

              <p className="text-content-tertiary flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <span className="inline-flex items-center gap-1">
                  <UserRound aria-hidden className="size-3" />
                  {item.owner ?? t('attention.unassigned')}
                </span>
                {item.since === null ? null : (
                  /* The exact moment stays in the tooltip: the list needs an age, an investigation needs a time. */
                  <time
                    dateTime={item.since}
                    title={new Date(item.since).toLocaleString(i18n.language)}
                  >
                    {formatRelativeTime(item.since, i18n.language)}
                  </time>
                )}
              </p>
            </div>

            <Link
              to={item.action.to}
              className="text-accent inline-flex shrink-0 items-center gap-1 text-sm hover:underline"
            >
              {item.action.label}
              <ArrowRight aria-hidden className="size-3.5 rtl:rotate-180" />
            </Link>
          </article>
        </li>
      ))}
    </ul>
  );
}
