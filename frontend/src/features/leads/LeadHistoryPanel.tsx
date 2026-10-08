import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import type { LeadHistoryEntry } from './types';

/**
 * What has happened to this lead, newest first.
 *
 * The read half of everything the pipeline writes, and the reason the notes are worth writing: without it a
 * note is a write nobody can see, which is a worse surface than having no notes at all — somebody records what
 * happened on a call and the next person reviewing the pipeline never learns it is there.
 *
 * **"Who did this" has an honest answer including when it is nobody.** A CRM sync and an arrival have no actor,
 * and the row says so rather than filling in whoever happened to be signed in. That is the question a pipeline
 * review asks, and an invented answer is worse than an absent one.
 *
 * Not a chart and not a timeline graphic. These are three to ten entries in words, and the words are the point.
 */
export function LeadHistoryPanel({
  entries,
  isLoading,
}: {
  entries: LeadHistoryEntry[] | undefined;
  isLoading: boolean;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;

  if (isLoading) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t('leads.history.title')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-2">
          <Skeleton className="h-5 w-full" />
          <Skeleton className="h-5 w-3/4" />
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('leads.history.title')}</CardTitle>
        <CardDescription>{t('leads.history.subtitle')}</CardDescription>
      </CardHeader>

      <CardContent>
        {entries === undefined || entries.length === 0 ? (
          <EmptyState
            title={t('leads.history.emptyTitle')}
            description={t('leads.history.emptyBody')}
          />
        ) : (
          <ol className="flex flex-col gap-3">
            {entries.map((entry) => (
              <li key={entry.id} className="flex flex-col gap-0.5">
                <div className="flex flex-wrap items-baseline gap-x-2">
                  <span className="text-content text-sm font-medium">{entry.type_label}</span>
                  {entry.from_stage_label === null || entry.to_stage_label === null ? null : (
                    <span className="text-content-secondary text-sm">
                      {t('leads.history.moved', {
                        from: entry.from_stage_label,
                        to: entry.to_stage_label,
                      })}
                    </span>
                  )}
                  <span className="text-content-tertiary text-xs">
                    {/*
                      The actor, or the fact that there was none. "The system" is said rather than left blank,
                      because a blank reads as missing data and this is an answer.
                    */}
                    {entry.actor_name === null
                      ? t('leads.history.bySystem')
                      : t('leads.history.byPerson', { name: entry.actor_name })}
                  </span>
                </div>

                <time
                  dateTime={entry.occurred_at}
                  className="text-content-tertiary text-xs tabular-nums"
                >
                  {new Date(entry.occurred_at).toLocaleString(locale, {
                    dateStyle: 'medium',
                    timeStyle: 'short',
                  })}
                </time>

                {entry.note === null ? null : (
                  <p className="text-content-secondary text-sm whitespace-pre-line">{entry.note}</p>
                )}
              </li>
            ))}
          </ol>
        )}
      </CardContent>
    </Card>
  );
}
