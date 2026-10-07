import { ArrowUpRight, Link2Off, TriangleAlert } from 'lucide-react';
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
import { Skeleton } from '@/shared/ui/skeleton';
import { Textarea } from '@/shared/ui/textarea';
import { ProposalTable } from './ProposalTable';
import type { ApprovalRequest } from './types';

type ApprovalDetailProps = {
  request: ApprovalRequest | undefined;
  isLoading: boolean;
  canDecide: boolean;
  canRequest: boolean;
  onDecide: (outcome: 'approved' | 'declined', fingerprint: string, reason?: string) => void;
  onWithdraw: (reason: string) => void;
  onEnact: (enactment: 'confirmed' | 'abandoned', note?: string) => void;
  /** A message from the server, including the 409 that says the proposal changed under the reader. */
  error: string | null;
};

/**
 * One request: what is being proposed, who asked, and what happens if it is approved.
 *
 * The sentence this panel exists to carry is the one above the decision buttons: **approving this does not make
 * the change.** This product does not write to the ad platforms, so a decision authorises a person, and the
 * change sits in the outstanding queue until somebody records making it. Without that sentence a decider
 * presses Approve believing the campaign is now paused, and tells a client so.
 *
 * The decision sends back the fingerprint shown here. If the proposal was amended in between, the server
 * answers 409 and the message appears above — a re-read rather than a retry, because the proposal is different
 * from the one that was read.
 */
export function ApprovalDetail({
  request,
  isLoading,
  canDecide,
  canRequest,
  onDecide,
  onWithdraw,
  onEnact,
  error,
}: ApprovalDetailProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const [pending, setPending] = useState<'declined' | 'withdrawn' | 'abandoned' | null>(null);
  const [reason, setReason] = useState('');

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
            title={t('approvals.detail.pickTitle')}
            description={t('approvals.detail.pickBody')}
          />
        </CardContent>
      </Card>
    );
  }

  const isPending = request.state === 'pending';
  const isOutstanding = request.state === 'approved' && request.enactment === 'awaiting_hand';

  return (
    <Card>
      <CardHeader className="gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle>{request.title}</CardTitle>
          <Badge tone="neutral">{request.kind_label}</Badge>
          <Badge tone={request.state === 'declined' ? 'danger' : 'neutral'}>
            {request.state_label}
          </Badge>
          {request.touches_live_spend ? (
            <Badge tone="warning">{t('approvals.detail.movesMoney')}</Badge>
          ) : null}
        </div>
        <p className="text-content-secondary text-sm">
          {request.client_name ?? t('approvals.detail.internal')}
          {request.project_name === null ? '' : ` · ${request.project_name}`}
          {request.requested_by_name === null
            ? ''
            : ` · ${t('approvals.detail.askedBy', { name: request.requested_by_name })}`}
        </p>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        {error === null ? null : <Alert tone="danger">{error}</Alert>}

        {/*
          The sentence the module exists for, shown before the buttons rather than after the decision. It is
          published per kind by the server, so it names what is missing rather than saying "not supported".
        */}
        {!request.is_enacted_here && request.enactment_blocked_because !== null ? (
          <Alert tone="info" className="flex items-start gap-2">
            <TriangleAlert aria-hidden className="mt-0.5 size-4 shrink-0" />
            <span>{request.enactment_blocked_because}</span>
          </Alert>
        ) : null}

        {isOutstanding ? (
          <Alert tone="warning">
            {t('approvals.detail.awaitingHand', { days: request.waiting_days ?? 0 })}
          </Alert>
        ) : null}

        <section className="flex flex-col gap-1" aria-label={t('approvals.detail.why')}>
          <h3 className="text-content text-sm font-semibold">{t('approvals.detail.why')}</h3>
          <p className="text-content-secondary text-sm leading-relaxed">{request.rationale}</p>
        </section>

        <ProposalTable proposal={request.proposal} fingerprint={request.fingerprint_short} />

        {request.decision_reason === null ? null : (
          <Alert tone={request.state === 'declined' ? 'danger' : 'info'}>
            {t('approvals.detail.decisionReason', { reason: request.decision_reason })}
          </Alert>
        )}

        {request.withdrawn_reason === null ? null : (
          <Alert tone="info">
            {t('approvals.detail.withdrawnReason', { reason: request.withdrawn_reason })}
          </Alert>
        )}

        <dl className="grid gap-3 sm:grid-cols-3">
          <div>
            <dt className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
              {t('approvals.detail.decision')}
            </dt>
            <dd className="text-content mt-1 text-sm">
              {request.decided_by_name === null
                ? '—'
                : t('approvals.detail.decidedBy', {
                    name: request.decided_by_name,
                    days: request.decision_days ?? 0,
                  })}
            </dd>
          </div>
          <div>
            <dt className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
              {t('approvals.detail.carriedOut')}
            </dt>
            <dd className="text-content mt-1 text-sm">
              {/*
                Said as somebody's claim, with their name, because that is exactly what it is. The product did
                not read the platform, and a reader who believes it did will stop checking.
              */}
              {request.enacted_by_name === null
                ? request.enactment_label
                : t('approvals.detail.enactedBy', {
                    name: request.enacted_by_name,
                    state: request.enactment_label,
                  })}
            </dd>
          </div>
          <div>
            <dt className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
              {t('approvals.detail.about')}
            </dt>
            <dd className="text-content mt-1 text-sm">
              {request.subject === null ? (
                '—'
              ) : request.subject.exists ? (
                <Link
                  to={request.subject.path}
                  className="text-accent inline-flex items-center gap-1 hover:underline"
                >
                  {request.subject.label ?? request.subject.type_label}
                  <ArrowUpRight aria-hidden className="size-3.5" />
                </Link>
              ) : (
                <span className="text-content-tertiary inline-flex items-center gap-1.5">
                  <Link2Off aria-hidden className="size-3.5" />
                  {t('approvals.detail.subjectGone', { type: request.subject.type_label })}
                </span>
              )}
            </dd>
          </div>
        </dl>

        {request.enactment_note === null ? null : (
          <p className="text-content-secondary text-sm">
            {t('approvals.detail.enactmentNote', { note: request.enactment_note })}
          </p>
        )}

        {pending !== null ? (
          <form
            className="flex flex-col gap-2"
            onSubmit={(event) => {
              event.preventDefault();

              if (reason.trim() === '') {
                return;
              }

              if (pending === 'declined') {
                onDecide('declined', request.fingerprint, reason.trim());
              } else if (pending === 'withdrawn') {
                onWithdraw(reason.trim());
              } else {
                onEnact('abandoned', reason.trim());
              }

              setPending(null);
              setReason('');
            }}
          >
            <Field label={t(`approvals.detail.reason.${pending}`)} required>
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
                {t('approvals.detail.confirm')}
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
        ) : (
          <div className="flex flex-wrap gap-2">
            {isPending && canDecide ? (
              <>
                <Button size="sm" onClick={() => onDecide('approved', request.fingerprint)}>
                  {t('approvals.detail.approve')}
                </Button>
                <Button variant="secondary" size="sm" onClick={() => setPending('declined')}>
                  {t('approvals.detail.decline')}
                </Button>
              </>
            ) : null}

            {isPending && canRequest ? (
              <Button variant="ghost" size="sm" onClick={() => setPending('withdrawn')}>
                {t('approvals.detail.withdraw')}
              </Button>
            ) : null}

            {isOutstanding && canRequest ? (
              <>
                <Button size="sm" onClick={() => onEnact('confirmed')}>
                  {t('approvals.detail.markDone')}
                </Button>
                <Button variant="ghost" size="sm" onClick={() => setPending('abandoned')}>
                  {t('approvals.detail.markAbandoned')}
                </Button>
              </>
            ) : null}
          </div>
        )}

        {request.supersedes_id === null ? null : (
          /*
            A changed proposal is a new request rather than an edited one, so this says which it replaces. Both
            decisions stay on the record, which is the point: a trail where one request was both declined and
            approved cannot say which decision the client was told about.
          */
          <p className="text-content-tertiary text-xs">{t('approvals.detail.supersedes')}</p>
        )}

        <p className="text-content-tertiary text-xs">
          {t('approvals.detail.waiting', {
            days: formatNumber(request.waiting_days ?? 0, locale),
          })}
        </p>
      </CardContent>
    </Card>
  );
}
