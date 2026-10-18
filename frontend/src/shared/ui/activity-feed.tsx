import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { cn } from '@/shared/lib/cn';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';

export interface ActivityEntry {
  id: string;
  /** Who did it. `null` for something the system did on a schedule — and it says so rather than guessing. */
  actor: string | null;
  /** What happened, already phrased by whoever knows the domain. "Moved Ramadan Push to Review." */
  summary: string;
  at: string;
  /** Where to see it, when there is somewhere to go. */
  to?: string | undefined;
}

/**
 * What has happened lately, in one shape, wherever it appears.
 *
 * A project, a client, a campaign and a lead all want this strip, and before this each grew its own. The
 * actor line matters more than it looks: "System" and "Layla" are different kinds of event, and a feed that
 * attributes a scheduled sync to the last person who touched the record is quietly lying about who did what.
 */
export function ActivityFeed({
  entries,
  isPending = false,
  limit,
  className,
}: {
  entries: ActivityEntry[];
  isPending?: boolean | undefined;
  limit?: number | undefined;
  className?: string | undefined;
}) {
  const { t, i18n } = useTranslation();

  if (isPending) {
    return (
      <div
        className={cn('flex flex-col gap-3', className)}
        role="status"
        aria-label={t('activity.loading')}
      >
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-10 w-full" />
      </div>
    );
  }

  if (entries.length === 0) {
    return <EmptyState title={t('activity.empty.title')} description={t('activity.empty.body')} />;
  }

  const shown = limit === undefined ? entries : entries.slice(0, limit);

  return (
    <ol className={cn('flex flex-col', className)} aria-label={t('activity.title')}>
      {shown.map((entry) => (
        <li
          key={entry.id}
          className="border-line-subtle flex flex-wrap items-baseline gap-x-2 gap-y-0.5 border-b py-2.5 last:border-0"
        >
          <span className="text-content text-sm">
            {entry.to === undefined ? (
              entry.summary
            ) : (
              <Link to={entry.to} className="hover:text-content-brand hover:underline">
                {entry.summary}
              </Link>
            )}
          </span>
          <span className="text-content-tertiary text-xs">
            {entry.actor ?? t('activity.system')}
            {' · '}
            <time dateTime={entry.at} title={new Date(entry.at).toLocaleString(i18n.language)}>
              {formatRelativeTime(entry.at, i18n.language)}
            </time>
          </span>
        </li>
      ))}
    </ol>
  );
}
