import { ArrowUpRight, CalendarOff, Link2Off, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { formatNumber } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import { Textarea } from '@/shared/ui/textarea';
import type { Task, TaskStateValue, TaskVocabulary } from './types';

type TaskDetailProps = {
  task: Task | undefined;
  vocabulary: TaskVocabulary | undefined;
  isLoading: boolean;
  canManage: boolean;
  onMove: (state: TaskStateValue, reason?: string) => void;
  onReschedule: (dueOn: string) => void;
  error: string | null;
};

/**
 * One task: where it is, where it can go, and what it is waiting on.
 *
 * **Mounted under a key of the selected task's id**, by the page, which is what clears the forms below when
 * the selection changes. A reason typed about one task must never travel to another, and a date box still
 * holding the previous task's date is one keystroke from rescheduling the wrong work. Resetting in an effect
 * would do the same thing a render later — long enough for a submit to carry the stale value.
 *
 * Two behaviours are the point of this panel.
 *
 * **The moves offered are the server's.** `allowed_next` comes from the API, so a target the board would
 * refuse is never on screen. The alternative — offering every state and showing an error — teaches people to
 * distrust the interface.
 *
 * **A move that needs a reason asks for it first.** Blocking and cancelling both do, and the reason box
 * appears before the request rather than after a refusal. It is also the only record of why the work stopped,
 * so the panel keeps showing it long after the work has moved on.
 */
export function TaskDetail({
  task,
  vocabulary,
  isLoading,
  canManage,
  onMove,
  onReschedule,
  error,
}: TaskDetailProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const [pending, setPending] = useState<TaskStateValue | null>(null);
  const [reason, setReason] = useState('');
  const [dueOn, setDueOn] = useState('');

  if (isLoading) {
    return (
      <Card>
        <CardContent className="flex flex-col gap-3 pt-5">
          <Skeleton className="h-5 w-1/2" />
          <Skeleton className="h-20 w-full" />
        </CardContent>
      </Card>
    );
  }

  if (task === undefined) {
    return (
      <Card>
        <CardContent className="pt-5">
          <EmptyState
            title={t('operations.detail.pickTitle')}
            description={t('operations.detail.pickBody')}
          />
        </CardContent>
      </Card>
    );
  }

  const state = vocabulary?.states.find((candidate) => candidate.state === task.state);
  const targets = (state?.allowed_next ?? []).map((value) => ({
    value,
    info: vocabulary?.states.find((candidate) => candidate.state === value),
  }));

  return (
    <Card>
      <CardHeader className="gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle>{task.title}</CardTitle>
          <Badge tone="neutral">{task.state_label}</Badge>
          <Badge tone={task.priority === 'urgent' ? 'danger' : 'neutral'}>
            {task.priority_label}
          </Badge>
          {task.is_overdue ? <Badge tone="danger">{task.horizon_label}</Badge> : null}
        </div>
        <p className="text-content-secondary text-sm">
          {task.client_name ?? t('operations.detail.internal')}
          {task.project_name === null ? '' : ` · ${task.project_name}`}
          {task.assignee_name === null
            ? ` · ${t('operations.detail.unassigned')}`
            : ` · ${task.assignee_name}`}
        </p>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        {error === null ? null : <Alert tone="danger">{error}</Alert>}

        {/*
          Kept on screen after the work has been unblocked, not only while it is blocked. It is the answer to
          "why did this take three weeks", and clearing it would lose the only record of the delay.
        */}
        {task.blocked_reason === null ? null : (
          <Alert
            tone={task.state === 'blocked' ? 'warning' : 'info'}
            className="flex items-start gap-2"
          >
            <TriangleAlert aria-hidden className="mt-0.5 size-4 shrink-0" />
            <span>
              {t(
                task.state === 'blocked'
                  ? 'operations.detail.blockedBy'
                  : 'operations.detail.wasBlockedBy',
                { reason: task.blocked_reason },
              )}
            </span>
          </Alert>
        )}

        {task.cancelled_reason === null ? null : (
          <Alert tone="info">
            {t('operations.detail.cancelledBecause', { reason: task.cancelled_reason })}
          </Alert>
        )}

        {task.description === null ? null : (
          <p className="text-content-secondary text-sm leading-relaxed">{task.description}</p>
        )}

        <dl className="grid gap-3 sm:grid-cols-3">
          <div>
            <dt className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
              {t('operations.detail.due')}
            </dt>
            <dd className="text-content mt-1 flex items-center gap-1.5 text-sm">
              {task.due_on === null ? (
                <>
                  <CalendarOff aria-hidden className="size-3.5" />
                  {/*
                    Said plainly rather than left blank. A blank reads as a date somebody forgot to load;
                    "no due date" is the fact, and it is the fact the overdue figure cannot see.
                  */}
                  {t('operations.detail.noDueDate')}
                </>
              ) : (
                <>
                  {task.due_on}
                  <span className="text-content-tertiary">
                    {task.days_until_due === null
                      ? ''
                      : `· ${t('operations.detail.daysAway', { count: task.days_until_due })}`}
                  </span>
                </>
              )}
            </dd>
          </div>
          <div>
            <dt className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
              {t('operations.detail.workingDays')}
            </dt>
            <dd className="text-content mt-1 text-sm">
              {/*
                Null while unfinished, and null when nobody recorded a start. Shown as a dash rather than a
                zero, because zero days of work is a claim and "unknown" is the truth.
              */}
              {task.working_days === null ? '—' : formatNumber(task.working_days, locale)}
            </dd>
          </div>
          <div>
            <dt className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
              {t('operations.detail.about')}
            </dt>
            <dd className="text-content mt-1 text-sm">
              {task.subject === null ? (
                '—'
              ) : task.subject.exists ? (
                <Link
                  to={task.subject.path}
                  className="text-accent inline-flex items-center gap-1 hover:underline"
                >
                  {task.subject.label ?? task.subject.type_label}
                  <ArrowUpRight aria-hidden className="size-3.5" />
                </Link>
              ) : (
                <span className="text-content-tertiary inline-flex items-center gap-1.5">
                  <Link2Off aria-hidden className="size-3.5" />
                  {/*
                    A deleted subject is said, not linked. There is no foreign key behind it, so the
                    alternative is a link that looks live and goes nowhere.
                  */}
                  {t('operations.detail.subjectGone', { type: task.subject.type_label })}
                </span>
              )}
            </dd>
          </div>
        </dl>

        {!canManage ? null : (
          <section className="flex flex-col gap-3" aria-label={t('operations.detail.moves')}>
            <h3 className="text-content text-sm font-semibold">{t('operations.detail.moves')}</h3>

            {targets.length === 0 ? (
              <p className="text-content-tertiary text-sm">{t('operations.detail.noMoves')}</p>
            ) : (
              <div className="flex flex-wrap gap-2">
                {targets.map((target) => (
                  <Button
                    key={target.value}
                    variant="secondary"
                    size="sm"
                    onClick={() => {
                      if (target.info?.needs_reason === true) {
                        /*
                          Asked for before the request, not after a refusal. The server refuses it too, and
                          so does a database CHECK — but being asked is what gets the reason written.
                        */
                        setPending(target.value);

                        return;
                      }

                      onMove(target.value);
                    }}
                  >
                    {target.info?.label ?? target.value}
                  </Button>
                ))}
              </div>
            )}

            {pending === null ? null : (
              <form
                className="flex flex-col gap-2"
                onSubmit={(event) => {
                  event.preventDefault();

                  if (reason.trim() === '') {
                    return;
                  }

                  onMove(pending, reason.trim());
                  setPending(null);
                  setReason('');
                }}
              >
                <Field
                  label={t(
                    pending === 'blocked'
                      ? 'operations.detail.blockReason'
                      : 'operations.detail.cancelReason',
                  )}
                  hint={t('operations.detail.reasonHint')}
                  required
                >
                  {(props) => (
                    <Textarea
                      {...props}
                      value={reason}
                      onChange={(event) => setReason(event.target.value)}
                      rows={2}
                      required
                    />
                  )}
                </Field>
                <div className="flex gap-2">
                  <Button type="submit" size="sm" disabled={reason.trim() === ''}>
                    {t('operations.detail.confirmMove')}
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      setPending(null);
                      setReason('');
                    }}
                  >
                    {t('actions.cancel')}
                  </Button>
                </div>
              </form>
            )}

            <form
              className="flex flex-wrap items-end gap-2"
              onSubmit={(event) => {
                event.preventDefault();
                onReschedule(dueOn);
              }}
            >
              <Field label={t('operations.detail.reschedule')} className="min-w-44">
                {(props) => (
                  <Input
                    {...props}
                    type="date"
                    value={dueOn}
                    onChange={(event) => setDueOn(event.target.value)}
                  />
                )}
              </Field>
              <Button type="submit" variant="secondary" size="sm">
                {t('operations.detail.setDate')}
              </Button>
              {task.due_on === null ? null : (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => {
                    /*
                     * Clearing a date is a first-class action. A board where a deadline can be set but never
                     * removed accumulates dates nobody agreed to, and then the overdue figure is noise.
                     */
                    setDueOn('');
                    onReschedule('');
                  }}
                >
                  {t('operations.detail.clearDate')}
                </Button>
              )}
            </form>
          </section>
        )}
      </CardContent>
    </Card>
  );
}
