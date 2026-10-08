import { CalendarOff, Lock, MessageSquare, Paperclip, TriangleAlert, Users } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { attachmentUrl } from './api';
import { formatNumber, readableSize } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import { Textarea } from '@/shared/ui/textarea';
import type {
  ClientRequest,
  ClientRequestStateValue,
  MessageAudienceValue,
  RequestVocabulary,
} from './types';

type RequestDetailProps = {
  request: ClientRequest | undefined;
  vocabulary: RequestVocabulary | undefined;
  isLoading: boolean;
  /** False when a client is reading their own request. Everything internal is hidden *and* never fetched. */
  isAgencyView: boolean;
  canTriage: boolean;
  onTriage: (state: ClientRequestStateValue, reason?: string) => void;
  onPromise: (promisedAt: string) => void;
  onAnswer: (audience: MessageAudienceValue, body: string) => void;
  /** Undefined where the reader may not upload — the control is then absent rather than disabled. */
  onAttach?: ((audience: MessageAudienceValue, file: File) => void) | undefined;
  /** The server's own refusal, which says what to send instead. */
  attachError?: string | null;
  isAttaching?: boolean;
  onRaiseTask: (title: string) => void;
  error: string | null;
};

/**
 * One client request, and the conversation on it.
 *
 * **Mounted under a key of the selected request**, by the page, which is what clears the forms below when the
 * selection changes. A reply typed about one client must never travel to another, and resetting in an effect
 * would do the same thing a render later — long enough for a submit to carry the wrong text.
 *
 * Three behaviours matter here.
 *
 * **The audience is chosen every time, with no default.** An agency's internal note about a client reaching that
 * client ends a relationship, and a default is exactly how it happens. The selected audience is restated on the
 * send button, because a control set once and forgotten is the one that leaks.
 *
 * **A client's own view offers no audience at all.** Their message is to the agency, and the server forces it
 * client-visible regardless — so offering a choice would be offering a lie.
 *
 * **A promised date is shown with the person who promised it**, or said to be absent. "We are on it" with no date
 * is the whole of what an unpromised client has been told, and the panel says so rather than leaving a blank.
 */
export function RequestDetail({
  request,
  vocabulary,
  isLoading,
  isAgencyView,
  canTriage,
  onTriage,
  onPromise,
  onAnswer,
  onAttach,
  attachError = null,
  isAttaching = false,
  onRaiseTask,
  error,
}: RequestDetailProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const [pending, setPending] = useState<ClientRequestStateValue | null>(null);
  const [reason, setReason] = useState('');
  const [body, setBody] = useState('');
  const [audience, setAudience] = useState<MessageAudienceValue | null>(null);
  const [promisedAt, setPromisedAt] = useState('');
  const [taskTitle, setTaskTitle] = useState('');

  if (isLoading) {
    return (
      <Card>
        <CardContent className="flex flex-col gap-3 pt-5">
          <Skeleton className="h-5 w-1/2" />
          <Skeleton className="h-24 w-full" />
        </CardContent>
      </Card>
    );
  }

  if (request === undefined) {
    return (
      <Card>
        <CardContent className="pt-5">
          <EmptyState
            title={t('requests.detail.pickTitle')}
            description={t('requests.detail.pickBody')}
          />
        </CardContent>
      </Card>
    );
  }

  const state = vocabulary?.states.find((candidate) => candidate.state === request.state);
  const targets = (state?.allowed_next ?? []).map((value) => ({
    value,
    info: vocabulary?.states.find((candidate) => candidate.state === value),
  }));
  const isOpen = state?.is_open ?? true;

  return (
    <Card>
      <CardHeader className="gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle>{request.title}</CardTitle>
          <Badge tone="neutral">{request.kind_label}</Badge>
          <Badge tone={request.state === 'declined' ? 'danger' : 'neutral'}>
            {request.state_label}
          </Badge>
          {request.is_overdue ? (
            <Badge tone="danger">{t('requests.detail.pastPromise')}</Badge>
          ) : null}
        </div>
        <p className="text-content-secondary text-sm">
          {request.client_name}
          {request.project_name === null ? '' : ` · ${request.project_name}`}
          {/*
            The client's own word, labelled as theirs. Never shown as a priority: the tasks raised from this
            request carry the agency's own, on the agency's own board.
          */}
          {` · ${t('requests.detail.clientSaid', { urgency: request.stated_urgency_label })}`}
        </p>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        {error === null ? null : <Alert tone="danger">{error}</Alert>}

        {request.needed_information === null ? null : (
          <Alert tone="warning" className="flex items-start gap-2">
            <TriangleAlert aria-hidden className="mt-0.5 size-4 shrink-0" />
            <span>
              {t('requests.detail.needsInformation', { what: request.needed_information })}
            </span>
          </Alert>
        )}

        {request.declined_reason === null ? null : (
          <Alert tone="danger">
            {t('requests.detail.declined', { reason: request.declined_reason })}
          </Alert>
        )}

        {request.detail === null ? null : (
          <p className="text-content-secondary text-sm leading-relaxed">{request.detail}</p>
        )}

        {/*
          A labelled section around the list rather than an aria-label on the `dl`: a definition list has no ARIA
          role of its own, so a label on it is not addressable — by anything navigating regions, or by a test.
        */}
        <section aria-label={t('requests.detail.dates')}>
          <dl className="grid gap-3 sm:grid-cols-3">
            <div>
              <dt className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
                {t('requests.detail.promised')}
              </dt>
              <dd className="text-content mt-1 flex items-center gap-1.5 text-sm">
                {request.promised_at === null ? (
                  <>
                    <CalendarOff aria-hidden className="size-3.5" />
                    {/*
                    Said rather than left blank. "We are on it" with no date is the whole of what an unpromised
                    client has been told, and a blank reads as a date somebody forgot to load.
                  */}
                    {t('requests.detail.nothingPromised')}
                  </>
                ) : (
                  <>
                    {request.promised_at}
                    {request.promised_by_name === null ? null : (
                      <span className="text-content-tertiary">
                        {t('requests.detail.promisedBy', { name: request.promised_by_name })}
                      </span>
                    )}
                  </>
                )}
              </dd>
            </div>
            <div>
              <dt className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
                {t('requests.detail.wanted')}
              </dt>
              <dd className="text-content mt-1 text-sm">{request.wanted_by ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
                {t(
                  request.is_unanswered ? 'requests.detail.waiting' : 'requests.detail.answeredIn',
                )}
              </dt>
              <dd className="text-content mt-1 text-sm">
                {t('requests.detail.days', {
                  count: request.waiting_for_answer_days,
                })}
              </dd>
            </div>
          </dl>
        </section>

        {!isAgencyView ? null : (
          <section aria-label={t('requests.detail.handling')}>
            <dl className="grid gap-3 sm:grid-cols-2">
              <div>
                <dt className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
                  {t('requests.detail.owner')}
                </dt>
                <dd className="text-content mt-1 text-sm">
                  {request.owner_name ?? t('requests.detail.noOwner')}
                </dd>
              </div>
              <div>
                <dt className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
                  {t('requests.detail.turnaround')}
                </dt>
                <dd className="text-content mt-1 text-sm">
                  {request.turnaround_days === null
                    ? '—'
                    : formatNumber(request.turnaround_days, locale)}
                </dd>
              </div>
            </dl>
          </section>
        )}

        <section className="flex flex-col gap-3" aria-label={t('requests.detail.conversation')}>
          <h3 className="text-content text-sm font-semibold">
            {t('requests.detail.conversation')}
          </h3>

          {request.messages.length === 0 ? (
            <p className="text-content-tertiary text-sm">{t('requests.detail.nothingSaid')}</p>
          ) : (
            <ul aria-label={t('requests.detail.messages')} className="flex flex-col gap-2">
              {request.messages.map((message) => (
                <li
                  key={message.id}
                  className={cn(
                    'border-line-subtle rounded-lg border p-3',
                    message.is_from_client ? 'bg-surface-sunken' : 'bg-surface',
                  )}
                >
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-content text-xs font-medium">
                      {message.author_name ?? t('requests.detail.somebody')}
                    </span>
                    {/*
                      Every message carries its audience as a visible badge, on the agency's side. A thread where
                      internal and client-visible messages look alike is a thread somebody replies to wrongly.
                    */}
                    {isAgencyView ? (
                      <Badge tone={message.is_visible_to_client ? 'accent' : 'neutral'}>
                        {message.is_visible_to_client ? (
                          <Users aria-hidden className="me-1 size-3" />
                        ) : (
                          <Lock aria-hidden className="me-1 size-3" />
                        )}
                        {t(
                          message.is_visible_to_client
                            ? 'requests.detail.clientSees'
                            : 'requests.detail.internalOnly',
                        )}
                      </Badge>
                    ) : null}
                  </div>
                  <p className="text-content-secondary mt-1 text-sm">{message.body}</p>
                </li>
              ))}
            </ul>
          )}

          <form
            className="flex flex-col gap-2"
            onSubmit={(event) => {
              event.preventDefault();

              if (body.trim() === '') {
                return;
              }

              /*
               * A client's own message goes to the client audience, which is what the server forces anyway. An
               * agency member must have chosen one; the button is disabled until they have.
               */
              onAnswer(isAgencyView ? (audience ?? 'internal') : 'client', body.trim());
              setBody('');
              setAudience(null);
            }}
          >
            <Field label={t('requests.detail.reply')}>
              {(props) => (
                <Textarea
                  {...props}
                  rows={2}
                  value={body}
                  onChange={(event) => setBody(event.target.value)}
                />
              )}
            </Field>

            {isAgencyView ? (
              <ChoiceGroup
                label={t('requests.detail.whoSees')}
                hint={t('requests.detail.whoSeesHint')}
              >
                <div className="flex flex-wrap gap-2">
                  {(vocabulary?.audiences ?? []).map((option) => (
                    <Choice
                      key={option.audience}
                      isSelected={audience === option.audience}
                      onSelect={() => setAudience(option.audience)}
                    >
                      {option.label}
                    </Choice>
                  ))}
                </div>
              </ChoiceGroup>
            ) : null}

            <div>
              <Button
                type="submit"
                size="sm"
                disabled={body.trim() === '' || (isAgencyView && audience === null)}
              >
                {/*
                  The audience is restated on the button. A control set once and forgotten is the one that leaks,
                  and this is the last thing a person reads before sending.
                */}
                {isAgencyView
                  ? audience === 'client'
                    ? t('requests.detail.sendToClient')
                    : audience === 'internal'
                      ? t('requests.detail.sendInternal')
                      : t('requests.detail.chooseAudience')
                  : t('requests.detail.send')}
              </Button>
            </div>
          </form>
        </section>

        <section className="flex flex-col gap-3" aria-label={t('requests.detail.files')}>
          <h3 className="text-content text-sm font-semibold">{t('requests.detail.files')}</h3>

          {request.attachments.length === 0 ? (
            <p className="text-content-tertiary text-sm">{t('requests.detail.noFiles')}</p>
          ) : (
            <ul aria-label={t('requests.detail.files')} className="flex flex-col gap-2">
              {request.attachments.map((file) => (
                <li
                  key={file.id}
                  className="border-line-subtle flex flex-wrap items-center gap-2 rounded-lg border p-3"
                >
                  <Paperclip aria-hidden className="text-content-tertiary size-4 shrink-0" />

                  {/*
                    A plain link, so the browser handles it — and the server answers with
                    `Content-Disposition: attachment`, so it is a file on a disk rather than a document in this
                    product's origin. Nothing is ever shown inline, including images: a PDF can carry a script and
                    a browser will guess at a type it was not told.
                  */}
                  <a
                    href={attachmentUrl(file.id)}
                    className="text-accent text-sm hover:underline"
                    download={file.name}
                  >
                    {file.name}
                  </a>

                  {/* The type the **bytes** said, which can differ from what the filename claims. */}
                  <span className="text-content-tertiary text-xs">
                    {file.type_label} · {readableSize(file.byte_size, locale)}
                  </span>

                  {isAgencyView ? (
                    <Badge tone={file.is_visible_to_client ? 'accent' : 'neutral'}>
                      {file.is_visible_to_client ? (
                        <Users aria-hidden className="me-1 size-3" />
                      ) : (
                        <Lock aria-hidden className="me-1 size-3" />
                      )}
                      {t(
                        file.is_visible_to_client
                          ? 'requests.detail.clientSees'
                          : 'requests.detail.internalOnly',
                      )}
                    </Badge>
                  ) : null}
                </li>
              ))}
            </ul>
          )}

          {attachError === null ? null : <Alert tone="danger">{attachError}</Alert>}

          {onAttach === undefined || !isOpen ? null : (
            <div className="flex flex-col gap-2">
              <Field label={t('requests.detail.addFile')} hint={t('requests.detail.addFileHint')}>
                {(props) => (
                  <input
                    {...props}
                    type="file"
                    disabled={isAttaching || (isAgencyView && audience === null)}
                    className="text-content-secondary text-sm"
                    onChange={(event) => {
                      const chosen = event.target.files?.[0];

                      if (chosen === undefined) {
                        return;
                      }

                      /*
                       * The same audience the reply would use, chosen above. A separate picker here would be a
                       * second place the choice is made, and the first time the two disagreed an internal
                       * screenshot would reach the client it is about.
                       */
                      onAttach(isAgencyView ? (audience ?? 'internal') : 'client', chosen);

                      /* Cleared, so choosing the same file twice is a second upload rather than nothing. */
                      event.target.value = '';
                    }}
                  />
                )}
              </Field>

              {isAgencyView && audience === null ? (
                <p className="text-content-tertiary text-xs">
                  {t('requests.detail.chooseAudienceFirst')}
                </p>
              ) : null}
            </div>
          )}
        </section>

        {!isAgencyView || !canTriage ? null : (
          <>
            <section className="flex flex-col gap-3" aria-label={t('requests.detail.moves')}>
              <h3 className="text-content text-sm font-semibold">{t('requests.detail.moves')}</h3>

              {targets.length === 0 ? (
                <p className="text-content-tertiary text-sm">{t('requests.detail.noMoves')}</p>
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
                            Asked for before the request, not after a refusal. Both reasons reach the client, so
                            the box is where the sentence they will read gets written.
                          */
                          setPending(target.value);

                          return;
                        }

                        onTriage(target.value);
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

                    onTriage(pending, reason.trim());
                    setPending(null);
                    setReason('');
                  }}
                >
                  <Field
                    label={t(
                      pending === 'declined'
                        ? 'requests.detail.declineReason'
                        : 'requests.detail.askReason',
                    )}
                    hint={t('requests.detail.reasonHint')}
                    required
                  >
                    {(props) => (
                      <Textarea
                        {...props}
                        rows={2}
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        required
                      />
                    )}
                  </Field>
                  <div className="flex gap-2">
                    <Button type="submit" size="sm" disabled={reason.trim() === ''}>
                      {t('requests.detail.confirmMove')}
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
                  onPromise(promisedAt);
                }}
              >
                <Field label={t('requests.detail.promiseADate')} className="min-w-44">
                  {(props) => (
                    <Input
                      {...props}
                      type="date"
                      value={promisedAt}
                      onChange={(event) => setPromisedAt(event.target.value)}
                    />
                  )}
                </Field>
                <Button type="submit" variant="secondary" size="sm">
                  {t('requests.detail.promise')}
                </Button>
                {request.promised_at === null ? null : (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      setPromisedAt('');
                      onPromise('');
                    }}
                  >
                    {t('requests.detail.withdrawPromise')}
                  </Button>
                )}
              </form>
            </section>

            <section className="flex flex-col gap-3" aria-label={t('requests.detail.work')}>
              <h3 className="text-content text-sm font-semibold">{t('requests.detail.work')}</h3>

              {request.tasks.length === 0 ? (
                <p className="text-content-tertiary text-sm">{t('requests.detail.noWork')}</p>
              ) : (
                <ul className="flex flex-col gap-1.5">
                  {request.tasks.map((task) => (
                    <li key={task.id} className="flex flex-wrap items-center gap-2 text-sm">
                      <span className="text-content">{task.title}</span>
                      <Badge tone="neutral">{task.state_label}</Badge>
                      {task.assignee_name === null ? null : (
                        <span className="text-content-tertiary text-xs">{task.assignee_name}</span>
                      )}
                    </li>
                  ))}
                </ul>
              )}

              {!isOpen ? (
                <p className="text-content-tertiary text-sm">
                  {t('requests.detail.settledNoWork')}
                </p>
              ) : request.kind === 'question' ? (
                <p className="text-content-tertiary flex items-start gap-1.5 text-sm">
                  <MessageSquare aria-hidden className="mt-0.5 size-3.5 shrink-0" />
                  {/*
                    Said rather than refused silently. A question needs an answer, and a board filled with cards
                    whose completion is a sentence in a reply is a board nobody trusts.
                  */}
                  {t('requests.detail.questionNeedsAnAnswer')}
                </p>
              ) : (
                <form
                  className="flex flex-wrap items-end gap-2"
                  onSubmit={(event) => {
                    event.preventDefault();

                    if (taskTitle.trim() === '') {
                      return;
                    }

                    onRaiseTask(taskTitle.trim());
                    setTaskTitle('');
                  }}
                >
                  <Field label={t('requests.detail.raiseTask')} className="min-w-56 flex-1">
                    {(props) => (
                      <Input
                        {...props}
                        value={taskTitle}
                        onChange={(event) => setTaskTitle(event.target.value)}
                      />
                    )}
                  </Field>
                  <Button
                    type="submit"
                    variant="secondary"
                    size="sm"
                    disabled={taskTitle.trim() === ''}
                  >
                    {t('requests.detail.addToBoard')}
                  </Button>
                </form>
              )}
            </section>
          </>
        )}
      </CardContent>
    </Card>
  );
}
