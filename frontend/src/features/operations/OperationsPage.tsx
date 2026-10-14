import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useClientsQuery } from '@/features/clients';
import { ApiError } from '@/shared/api/client';
import { useBoardRealtime } from '@/shared/realtime/useBoardRealtime';
import { formatNumber, formatRate } from '@/shared/lib/format';
import { cn } from '@/shared/lib/cn';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Card, CardContent } from '@/shared/ui/card';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { LiveBadge } from '@/shared/ui/live-badge';
import { Skeleton } from '@/shared/ui/skeleton';
import { PageHeader } from '@/shared/ui/page-header';
import { DueProfileChart } from './DueProfileChart';
import { LoadByPersonChart } from './LoadByPersonChart';
import { TaskComposer } from './TaskComposer';
import { TaskDetail } from './TaskDetail';
import {
  useAssigneesQuery,
  useAssignTaskMutation,
  useMoveTaskMutation,
  useOpenTaskMutation,
  useTaskBoardQuery,
  useTaskQuery,
  useTaskVocabularyQuery,
  useUpdateTaskMutation,
} from './useTasks';

/** Who the board is narrowed to. `me` and `unassigned` are resolved by the server, not here. */
type Audience = 'everyone' | 'me' | 'unassigned';

/**
 * The operations board.
 *
 * Read-only as a board: the moves live beside the work in the detail panel rather than behind a drag. Moving
 * a task is a decision with consequences — blocking it needs a reason, cancelling it needs a different one,
 * and finishing it records a time — and a column drag has nowhere to put any of them.
 *
 * The figures above the columns are the point of the page. **Overdue is published beside undated**, because
 * one is a count of late work and the other is a count of work that cannot be late, and a board showing only
 * the first invites a reader to assume the rest is on time. When too much of the board carries no date, the
 * chart below says the overdue figure is not the measure of lateness anybody thinks it is.
 */
export function OperationsPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { can, user } = useSession();

  const canView = can(PERMISSIONS.tasksView);
  const canManage = can(PERMISSIONS.tasksManage);

  const [clientId, setClientId] = useState<string | null>(null);
  const [audience, setAudience] = useState<Audience>('everyone');
  const [selected, setSelected] = useState<string | null>(null);
  const [isComposing, setIsComposing] = useState(false);

  const clients = useClientsQuery({ page: 1, per_page: 100, sort: 'name', direction: 'asc' });
  const vocabulary = useTaskVocabularyQuery(canView);
  const board = useTaskBoardQuery(
    { clientId, assignee: audience === 'everyone' ? null : audience },
    canView,
  );
  const task = useTaskQuery(selected);

  const move = useMoveTaskMutation();
  const update = useUpdateTaskMutation();
  const assign = useAssignTaskMutation();
  /* Who the selected task can be given to, from the server — see `OwnerDirectory`. */
  const assignees = useAssigneesQuery(selected, canManage);
  const open = useOpenTaskMutation();

  /*
   * Live, where the socket is configured. The event is a nudge with no rows in it, so the only response is
   * to read the board again over the authenticated endpoint — see `useBoardRealtime`.
   */
  const isLive = useBoardRealtime('tasks', user?.active_workspace?.id ?? null);

  const error = useMemo(() => {
    for (const mutation of [move, update, assign, open]) {
      if (mutation.error instanceof ApiError && mutation.error.message !== '') {
        return mutation.error.message;
      }
    }

    return null;
  }, [move, update, assign, open]);

  if (!canView) {
    return (
      <EmptyState title={t('operations.denied.title')} description={t('operations.denied.body')} />
    );
  }

  const workload = board.data?.workload;

  return (
    <div className="ds-page">
      <PageHeader
        title={t('operations.title')}
        description={t('operations.subtitle')}
        actions={<LiveBadge isLive={isLive} />}
        filters={
          <div className="flex flex-wrap gap-6">
            <ChoiceGroup label={t('operations.scope.client')}>
              <div className="flex flex-wrap gap-2">
                <Choice isSelected={clientId === null} onSelect={() => setClientId(null)}>
                  {t('operations.scope.allClients')}
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

            <ChoiceGroup label={t('operations.scope.audience')}>
              <div className="flex flex-wrap gap-2">
                {(['everyone', 'me', 'unassigned'] as const).map((option) => (
                  <Choice
                    key={option}
                    isSelected={audience === option}
                    onSelect={() => setAudience(option)}
                  >
                    {t(`operations.scope.${option}`)}
                  </Choice>
                ))}
              </div>
            </ChoiceGroup>
          </div>
        }
      />

      {board.isError ? <Alert tone="danger">{t('operations.loadFailed')}</Alert> : null}

      {board.isPending || workload === undefined || board.data === undefined ? (
        <Skeleton className="h-32 w-full" />
      ) : (
        <>
          <section
            aria-label={t('operations.counts')}
            className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"
          >
            {[
              { key: 'open', value: workload.open, tone: 'neutral' as const },
              { key: 'overdue', value: workload.overdue, tone: 'danger' as const },
              /*
               * Beside overdue, never inside it. Two of these figures are counts of work that *is* late and
               * work that *cannot be* late, and a page showing only the first is a page that misleads.
               */
              { key: 'undated', value: workload.undated, tone: 'warning' as const },
              { key: 'unassigned', value: workload.unassigned, tone: 'warning' as const },
              { key: 'waiting', value: workload.waiting, tone: 'neutral' as const },
            ].map((figure) => (
              <Card key={figure.key}>
                <CardContent className="flex flex-col gap-1 pt-5">
                  <span className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
                    {t(`operations.count.${figure.key}`)}
                  </span>
                  <span className="text-content text-2xl font-semibold">
                    {formatNumber(figure.value, locale)}
                  </span>
                  {figure.value > 0 && figure.tone !== 'neutral' ? (
                    <Badge tone={figure.tone}>{t(`operations.count.${figure.key}Note`)}</Badge>
                  ) : null}
                </CardContent>
              </Card>
            ))}
          </section>

          <p className="text-content-tertiary text-sm">
            {/*
              A rate with no denominator is not a rate. Nothing settled means no completion figure, said
              rather than printed as zero percent.
            */}
            {workload.completion_rate === null
              ? t('operations.completion.none')
              : t('operations.completion.rate', {
                  rate: formatRate(workload.completion_rate, locale),
                  settled: formatNumber(workload.done + workload.cancelled, locale),
                })}
          </p>

          <div className="grid gap-6 xl:grid-cols-2">
            <DueProfileChart workload={workload} horizons={vocabulary.data?.horizons ?? []} />
            <LoadByPersonChart
              load={board.data.load}
              priorities={vocabulary.data?.priorities ?? []}
              present={board.data.priorities_present}
            />
          </div>

          {board.data.is_truncated ? <Alert tone="info">{t('operations.truncated')}</Alert> : null}

          <section
            aria-label={t('operations.board')}
            /* A board scrolls sideways on a phone, and a region that scrolls with no tab stop hides its own
               last column from anybody on a keyboard. See `ScrollRegion`, which this is the section-shaped
               version of — a landmark already named, so only the stop was missing. */
            tabIndex={0}
            className="flex gap-4 overflow-x-auto pb-2"
          >
            {board.data.columns.map((column) => (
              <div key={column.state} className="flex w-64 shrink-0 flex-col gap-2">
                <h2 className="text-content-secondary flex items-center justify-between text-xs font-medium tracking-wide uppercase">
                  {column.label}
                  {/*
                    The column's own total, from the whole board. The cards below are a page of it, and
                    publishing the page's length instead would be publishing "the first fifty".
                  */}
                  <span className="text-content-tertiary tabular-nums">
                    {formatNumber(column.total, locale)}
                  </span>
                </h2>

                <ul className="flex flex-col gap-2">
                  {column.tasks.map((card) => (
                    <li key={card.id}>
                      <button
                        type="button"
                        onClick={() => setSelected(card.id)}
                        aria-pressed={selected === card.id}
                        className={cn(
                          'bg-surface border-line-subtle w-full rounded-2xl border p-3 text-start shadow-xs',
                          'ease-standard transition-[border-color,box-shadow] duration-[var(--ds-duration-fast)]',
                          'hover:border-line-strong focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none',
                          selected === card.id && 'border-accent ring-accent/25 ring-1',
                        )}
                      >
                        <span className="text-content block text-sm font-medium">{card.title}</span>
                        <span className="text-content-tertiary mt-1 block text-xs">
                          {card.client_name ?? t('operations.detail.internal')}
                          {card.assignee_name === null
                            ? ` · ${t('operations.detail.unassigned')}`
                            : ` · ${card.assignee_name}`}
                        </span>
                        <span className="mt-2 flex flex-wrap items-center gap-1.5">
                          {card.priority === 'urgent' || card.priority === 'high' ? (
                            <Badge tone={card.priority === 'urgent' ? 'danger' : 'warning'}>
                              {card.priority_label}
                            </Badge>
                          ) : null}
                          {/*
                            The horizon rather than a date. "No due date" on a card is the fact a board
                            usually hides, and it is the one that explains the overdue figure.
                          */}
                          <Badge
                            tone={
                              card.horizon === 'overdue'
                                ? 'danger'
                                : card.horizon === 'undated'
                                  ? 'warning'
                                  : 'neutral'
                            }
                          >
                            {card.horizon_label}
                          </Badge>
                          {card.subject === null ? null : (
                            <Badge tone="neutral">{card.subject.type_label}</Badge>
                          )}
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </section>
        </>
      )}

      <TaskDetail
        /*
         * Keyed on the selection, which remounts the panel and clears its forms. See the note on
         * `TaskDetail`: a reason typed about one task must not survive into another.
         */
        key={selected ?? 'none'}
        task={selected === null ? undefined : task.data}
        vocabulary={vocabulary.data}
        isLoading={selected !== null && task.isPending}
        canManage={canManage}
        onMove={(state, reason) => {
          if (selected !== null) {
            move.mutate({ taskId: selected, state, ...(reason === undefined ? {} : { reason }) });
          }
        }}
        onReschedule={(dueOn) => {
          if (selected !== null) {
            update.mutate({ taskId: selected, dueOn });
          }
        }}
        assignees={selected === null ? undefined : assignees.data}
        onAssign={(assigneeId) => {
          if (selected !== null) {
            assign.mutate({ taskId: selected, assigneeId });
          }
        }}
        error={error}
      />

      {!canManage ? null : isComposing ? (
        <TaskComposer
          priorities={vocabulary.data?.priorities ?? []}
          clients={(clients.data?.data ?? []).map((client) => ({
            id: client.id,
            name: client.name,
          }))}
          isSaving={open.isPending}
          error={error}
          onSubmit={(input) => {
            open.mutate(input, { onSuccess: () => setIsComposing(false) });
          }}
        />
      ) : (
        <div>
          <button
            type="button"
            onClick={() => setIsComposing(true)}
            className="text-accent text-sm font-medium hover:underline"
          >
            {t('operations.composer.open')}
          </button>
        </div>
      )}
    </div>
  );
}
