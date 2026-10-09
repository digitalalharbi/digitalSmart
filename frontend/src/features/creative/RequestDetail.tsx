import { AlertTriangle, Lock, MessageSquare, Users } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatNumber } from '@/shared/lib/format';
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
import { SpecPanel } from './SpecPanel';
import type {
  CommentVisibilityValue,
  CreativeOwner,
  CreativeRequest,
  CreativeStateValue,
  CreativeVocabulary,
} from './types';

type RequestDetailProps = {
  request: CreativeRequest | undefined;
  vocabulary: CreativeVocabulary | undefined;
  isLoading: boolean;
  canApprove: boolean;
  onDecide: (versionId: string, outcome: 'approved' | 'changes_requested', reason?: string) => void;
  onComment: (body: string, visibility: CommentVisibilityValue) => void;
  /** True for a role that may move work and hand it over. The server refuses either way. */
  canManage: boolean;
  /**
   * The legal moves, from the server's own vocabulary.
   *
   * `allowed_next` comes from the API so a target the board would refuse is never on screen. Offering every
   * state and showing an error afterwards teaches people to distrust the interface.
   */
  onMove: (state: CreativeStateValue, reason?: string) => void;
  /** Who this brief can be put in the name of, from the server. */
  owners: CreativeOwner[] | undefined;
  onAssign: (ownerId: string | null) => void;
  error: string | null;
};

/**
 * One piece of work: its brief, its versions, what the specification made of each, and the decisions.
 *
 * Versions are listed **newest last**, like a conversation, and each keeps its own decision. An approval shown
 * against version one while version two sits unapproved below it is the honest picture — and the banner above
 * says so, because a request whose state reads "approved" while its newest file is unseen is the one thing an
 * approval trail must never let somebody believe.
 */
export function RequestDetail({
  request,
  vocabulary,
  isLoading,
  canApprove,
  onDecide,
  onComment,
  canManage,
  onMove,
  owners,
  onAssign,
  error,
}: RequestDetailProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const [rejecting, setRejecting] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [comment, setComment] = useState('');
  const [moving, setMoving] = useState<CreativeStateValue | null>(null);
  const [moveReason, setMoveReason] = useState('');
  const [visibility, setVisibility] = useState<CommentVisibilityValue>('internal');

  if (isLoading) {
    return (
      <Card>
        <CardContent className="flex flex-col gap-3 pt-5">
          <Skeleton className="h-5 w-1/2" />
          <Skeleton className="h-24 w-full" />
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
            title={t('creative.detail.pickTitle')}
            description={t('creative.detail.pickBody')}
          />
        </CardContent>
      </Card>
    );
  }

  const newest = request.versions[request.versions.length - 1];

  return (
    <Card>
      <CardHeader className="gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle>{request.title}</CardTitle>
          <Badge tone="neutral">{request.state_label}</Badge>
          <Badge tone="neutral">{request.kind_label}</Badge>
          {request.is_overdue ? <Badge tone="danger">{t('creative.card.overdue')}</Badge> : null}
        </div>
        <p className="text-content-secondary text-sm">
          {request.client_name}
          {request.placement === null
            ? ` · ${t('creative.detail.noPlacement')}`
            : ` · ${request.provider ?? ''} ${request.placement}`}
        </p>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        {error === null ? null : <Alert tone="danger">{error}</Alert>}

        {/*
          The statement an approval trail exists to make. A request sitting in "approved" with a newer version
          nobody has decided on is stale, and the reader has to know before they tell a client it is signed off.
        */}
        {request.state === 'approved' && !request.newest_is_approved ? (
          <Alert tone="warning" className="flex items-start gap-2">
            <AlertTriangle aria-hidden className="mt-0.5 size-4 shrink-0" />
            <span>{t('creative.detail.staleApproval')}</span>
          </Alert>
        ) : null}

        {request.brief === null ? null : (
          <p className="text-content-secondary text-sm leading-relaxed">{request.brief}</p>
        )}

        {!canManage ? null : (
          <section className="border-line-subtle flex flex-col gap-3 border-t pt-4">
            {/*
              Who is making it. The board could show an owner from the moment a brief was written and nothing
              could ever change it, so work could not be handed over at all.
            */}
            <Field label={t('creative.detail.owner')} hint={t('creative.detail.ownerHint')}>
              {(props) => (
                <select
                  {...props}
                  className="border-line bg-surface text-content rounded-lg border px-3 py-2 text-sm"
                  value={request.owner_id ?? ''}
                  onChange={(event) =>
                    onAssign(event.target.value === '' ? null : event.target.value)
                  }
                >
                  <option value="">{t('creative.detail.noOwner')}</option>
                  {(owners ?? []).map((person) => (
                    <option key={person.id} value={person.id}>
                      {person.name}
                    </option>
                  ))}
                </select>
              )}
            </Field>

            {/*
              Where it can go next, from the server's own vocabulary rather than a list held here: a target the
              board would refuse is never on screen. Cancelling asks for its reason first, because the server
              requires one and because that reason is the only record of why the work stopped.
            */}
            {moving === null ? (
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
                  {t('creative.detail.moveTo')}
                </span>
                {(
                  vocabulary?.states.find((state) => state.state === request.state)?.allowed_next ??
                  []
                ).map((next) => (
                  <Button
                    key={next}
                    type="button"
                    variant="secondary"
                    size="sm"
                    onClick={() => {
                      if (next === 'cancelled') {
                        setMoving(next);

                        return;
                      }

                      onMove(next);
                    }}
                  >
                    {vocabulary?.states.find((state) => state.state === next)?.label ?? next}
                  </Button>
                ))}
              </div>
            ) : (
              <form
                className="flex flex-col gap-2"
                onSubmit={(event) => {
                  event.preventDefault();

                  if (moveReason.trim() === '') {
                    return;
                  }

                  onMove(moving, moveReason.trim());
                  setMoving(null);
                  setMoveReason('');
                }}
              >
                <Field label={t('creative.detail.cancelReason')} required>
                  {(props) => (
                    <Input
                      {...props}
                      value={moveReason}
                      onChange={(event) => setMoveReason(event.target.value)}
                      required
                    />
                  )}
                </Field>
                <div className="flex gap-2">
                  <Button type="submit" size="sm" disabled={moveReason.trim() === ''}>
                    {t('creative.detail.confirmCancel')}
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      setMoving(null);
                      setMoveReason('');
                    }}
                  >
                    {t('actions.cancel')}
                  </Button>
                </div>
              </form>
            )}
          </section>
        )}

        <section className="flex flex-col gap-3" aria-label={t('creative.detail.versions')}>
          <h3 className="text-content text-sm font-semibold">{t('creative.detail.versions')}</h3>

          {request.versions.length === 0 ? (
            <EmptyState
              title={t('creative.detail.noVersionsTitle')}
              description={t('creative.detail.noVersionsBody')}
            />
          ) : (
            <ol className="flex flex-col gap-3">
              {request.versions.map((version) => (
                <li
                  key={version.id}
                  className="border-line-subtle flex flex-col gap-3 rounded-xl border p-3"
                >
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="flex flex-wrap items-center gap-2">
                      <span className="text-content text-sm font-medium">
                        {t('creative.detail.version', { number: version.number })}
                      </span>
                      {version.label === null ? null : (
                        <span className="text-content-secondary text-xs">{version.label}</span>
                      )}
                      {version.decision === null ? (
                        <Badge tone="neutral">{t('creative.detail.undecided')}</Badge>
                      ) : (
                        <Badge tone={version.is_approved ? 'success' : 'warning'}>
                          {version.decision_label}
                        </Badge>
                      )}
                    </span>
                    <span className="text-content-tertiary text-xs tabular-nums">
                      {new Date(version.created_at).toLocaleDateString(locale, {
                        dateStyle: 'medium',
                      })}
                      {version.uploaded_by_name === null ? '' : ` · ${version.uploaded_by_name}`}
                    </span>
                  </div>

                  {/*
                    What was measured, with nulls shown as "not measured" rather than as zero. A width this
                    product could not read is unknown, and any interface rendering a zero would show a very
                    small image.
                  */}
                  <dl className="text-content-secondary flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    {[
                      [
                        t('creative.measure.size'),
                        version.measurements.width_px === null ||
                        version.measurements.height_px === null
                          ? null
                          : `${formatNumber(version.measurements.width_px, locale)}×${formatNumber(version.measurements.height_px, locale)}`,
                      ],
                      [t('creative.measure.ratio'), version.measurements.aspect_ratio],
                      [t('creative.measure.format'), version.measurements.format],
                      [
                        t('creative.measure.duration'),
                        version.measurements.duration_seconds === null
                          ? null
                          : `${formatNumber(version.measurements.duration_seconds, locale, 1)}s`,
                      ],
                      [
                        t('creative.measure.length'),
                        version.measurements.text_length === null
                          ? null
                          : formatNumber(version.measurements.text_length, locale),
                      ],
                    ].map(([label, value]) => (
                      <span key={String(label)} className="flex gap-1">
                        <dt className="text-content-tertiary">{label}</dt>
                        <dd className="tabular-nums">{value ?? t('creative.spec.notMeasured')}</dd>
                      </span>
                    ))}
                  </dl>

                  <SpecPanel report={version.spec} />

                  {version.decision_reason === null ? null : (
                    <p className="text-content-secondary text-xs leading-relaxed">
                      <strong className="font-medium">{version.decided_by_name ?? ''}</strong>{' '}
                      {version.decision_reason}
                    </p>
                  )}

                  {/*
                    Only on the newest version, and only for somebody who may approve. Deciding on an old
                    version while a newer one exists would put a signature on a file nobody is shipping.
                  */}
                  {canApprove && newest !== undefined && newest.id === version.id ? (
                    rejecting === version.id ? (
                      <form
                        className="flex flex-col gap-2"
                        onSubmit={(event) => {
                          event.preventDefault();
                          onDecide(version.id, 'changes_requested', reason);
                          setRejecting(null);
                          setReason('');
                        }}
                      >
                        <Field label={t('creative.detail.whatToChange')} required>
                          {(props) => (
                            <Input
                              {...props}
                              value={reason}
                              onChange={(event) => setReason(event.target.value)}
                              required
                            />
                          )}
                        </Field>
                        <div className="flex gap-2">
                          <Button type="submit" size="sm">
                            {t('creative.detail.sendBack')}
                          </Button>
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setRejecting(null)}
                          >
                            {t('actions.cancel')}
                          </Button>
                        </div>
                      </form>
                    ) : (
                      <div className="flex flex-wrap gap-2">
                        <Button
                          type="button"
                          size="sm"
                          onClick={() => onDecide(version.id, 'approved')}
                        >
                          {t('creative.detail.approve')}
                        </Button>
                        <Button
                          type="button"
                          variant="secondary"
                          size="sm"
                          onClick={() => setRejecting(version.id)}
                        >
                          {t('creative.detail.requestChanges')}
                        </Button>
                      </div>
                    )
                  ) : null}
                </li>
              ))}
            </ol>
          )}
        </section>

        <section className="flex flex-col gap-3" aria-label={t('creative.detail.comments')}>
          <h3 className="text-content flex items-center gap-1.5 text-sm font-semibold">
            <MessageSquare aria-hidden className="size-3.5" />
            {t('creative.detail.comments')}
          </h3>

          <ul className="flex flex-col gap-2">
            {request.comments.map((entry) => (
              <li
                key={entry.id}
                className="border-line-subtle flex flex-col gap-1 rounded-lg border p-3"
              >
                <span className="flex flex-wrap items-center gap-2 text-xs">
                  <span className="text-content font-medium">{entry.author_name ?? ''}</span>
                  {/*
                    The audience, on every comment and never inferred. An internal note that looks like a client
                    message is how one gets forwarded.
                  */}
                  <Badge tone={entry.is_visible_to_client ? 'info' : 'neutral'}>
                    {entry.is_visible_to_client ? (
                      <Users aria-hidden className="size-3" />
                    ) : (
                      <Lock aria-hidden className="size-3" />
                    )}
                    {entry.visibility_label}
                  </Badge>
                  {entry.version_number === null ? null : (
                    <span className="text-content-tertiary">
                      {t('creative.detail.version', { number: entry.version_number })}
                    </span>
                  )}
                </span>
                <p className="text-content-secondary text-sm leading-relaxed">{entry.body}</p>
              </li>
            ))}
          </ul>

          <form
            className="flex flex-col gap-2"
            onSubmit={(event) => {
              event.preventDefault();

              if (comment.trim() === '') {
                return;
              }

              onComment(comment, visibility);
              setComment('');
            }}
          >
            <Field label={t('creative.detail.addComment')}>
              {(props) => (
                <Textarea
                  {...props}
                  rows={2}
                  value={comment}
                  onChange={(event) => setComment(event.target.value)}
                />
              )}
            </Field>

            {/*
              The audience is chosen, not defaulted, every time. A sticky default is how "the client hated the
              last one" reaches the client.
            */}
            <ChoiceGroup
              label={t('creative.detail.audience')}
              hint={t('creative.detail.audienceHint')}
            >
              <div className="flex flex-wrap gap-2">
                {(vocabulary?.visibilities ?? []).map((option) => (
                  <Choice
                    key={option.visibility}
                    isSelected={visibility === option.visibility}
                    onSelect={() => setVisibility(option.visibility)}
                  >
                    {option.label}
                  </Choice>
                ))}
              </div>
            </ChoiceGroup>

            <Button type="submit" size="sm" className="self-start">
              {t('creative.detail.postComment')}
            </Button>
          </form>
        </section>
      </CardContent>
    </Card>
  );
}
