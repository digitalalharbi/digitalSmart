import { useState } from 'react';
import { useParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useProjectsQuery } from '@/features/projects';
import { ApiError } from '@/shared/api/client';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Breadcrumbs } from '@/shared/ui/breadcrumbs';
import { Button } from '@/shared/ui/button';
import { Field } from '@/shared/ui/field';
import { FilterSelect } from '@/shared/ui/filter-select';
import { Input } from '@/shared/ui/input';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { Textarea } from '@/shared/ui/textarea';
import { SlaBadge, StatusBadge } from './AccessBadges';
import { CopyLink } from './AccessRequestsPage';
import {
  useAccessInboxQuery,
  useAccessRequestQuery,
  useApproveMutation,
  useAssignMutation,
  useContactMutation,
  useFollowUpMutation,
  useInviteMutation,
  useMoveMutation,
  useNoteMutation,
  useRejectMutation,
  useSharedMutation,
} from './api';
import { INVITABLE_ROLES, type AccessDetail, type AccessInbox, type ContactAction } from './types';

const CONTACT_ACTIONS: ContactAction[] = [
  'email_sent',
  'call_attempted',
  'contacted',
  'whatsapp_contacted',
];

/**
 * One request: who, what they said, what has been tried, and what is left to do.
 *
 * Contact actions record what a person says they did. The page says, beside them, that email and WhatsApp delivery are
 * awaiting credentials — nothing here sends, so nothing here is ever shown as sent.
 */
export function AccessRequestDetailPage() {
  const { t } = useTranslation();
  const { id = '' } = useParams();
  const { can } = useSession();
  const query = useAccessRequestQuery(id);
  const inbox = useAccessInboxQuery();

  if (!can(PERMISSIONS.usersInvite)) {
    return <Alert tone="warning">{t('access.forbidden')}</Alert>;
  }

  if (query.isPending) {
    return <Skeleton className="h-64 w-full" />;
  }

  if (query.isError) {
    return <Alert tone="danger">{t('access.detail.failed')}</Alert>;
  }

  const request = query.data.request;

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs
        label={t('access.detail.trail')}
        items={[{ label: t('access.title'), to: '/access-requests' }, { label: request.name }]}
      />
      <PageHeader title={request.name} description={t(`access.kind.${request.kind}`)} />

      <div className="flex flex-wrap items-center gap-2">
        <StatusBadge status={request.status} />
        <SlaBadge sla={request.sla} />
        {request.possible_duplicate || request.linked.length > 0 ? (
          <Badge tone="warning">{t('access.duplicate')}</Badge>
        ) : null}
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
        <div className="flex min-w-0 flex-col gap-6">
          <ContactDetails request={request} />
          <WorkPanel
            request={request}
            owners={inbox.data?.owners ?? []}
            delivery={query.data.delivery}
          />
          <DecisionPanel request={request} />
          <Timeline request={request} />
        </div>
        <div className="flex min-w-0 flex-col gap-6">
          <Milestones request={request} />
          {request.linked.length === 0 ? null : (
            <section
              aria-label={t('access.detail.linked')}
              className="border-line bg-surface rounded-2xl border p-4"
            >
              <h2 className="text-content mb-2 text-sm font-semibold">
                {t('access.detail.linked')}
              </h2>
              <ul className="flex flex-col gap-1 text-sm">
                {request.linked.map((other) => (
                  <li key={other.id} dir="auto">
                    <a className="underline" href={`/access-requests/${other.id}`}>
                      {other.name}
                    </a>{' '}
                    <span className="text-content-tertiary">
                      {t(`access.status.${other.status}`)}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>
      </div>
    </div>
  );
}

function ContactDetails({ request }: { request: AccessDetail }) {
  const { t } = useTranslation();
  const rows: Array<[string, string | null]> = [
    [t('access.fields.email'), request.email],
    [t('access.fields.phone'), request.phone],
    [t('access.fields.company'), request.company],
    [t('access.fields.jobTitle'), request.job_title],
    [t('access.fields.contact'), t(`access.contact.${request.preferred_contact}`)],
    [t('access.fields.need'), request.need === null ? null : t(`access.need.${request.need}`)],
  ];

  return (
    <section
      aria-label={t('access.detail.contact')}
      className="border-line bg-surface rounded-2xl border p-4"
    >
      <h2 className="text-content mb-3 text-sm font-semibold">{t('access.detail.contact')}</h2>
      <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
        {rows
          .filter(([, value]) => value !== null && value !== '')
          .map(([label, value]) => (
            <div key={label} className="flex min-w-0 gap-2">
              <dt className="text-content-tertiary shrink-0">{label}</dt>
              <dd dir="auto" className="min-w-0 break-words">
                {value}
              </dd>
            </div>
          ))}
      </dl>
      {request.message === null ? null : (
        <>
          <h3 className="text-content-secondary mt-4 mb-1 text-xs font-medium">
            {t('access.fields.message')}
          </h3>
          <p dir="auto" className="text-content text-sm whitespace-pre-wrap">
            {request.message}
          </p>
        </>
      )}
    </section>
  );
}

function WorkPanel({
  request,
  owners,
  delivery,
}: {
  request: AccessDetail;
  owners: AccessInbox['owners'];
  delivery: AccessInbox['delivery'];
}) {
  const { t } = useTranslation();
  const assign = useAssignMutation();
  const contact = useContactMutation();
  const note = useNoteMutation();
  const followUp = useFollowUpMutation();
  const move = useMoveMutation();
  const [contactNote, setContactNote] = useState('');
  const [body, setBody] = useState('');
  const [when, setWhen] = useState('');
  const open = ['new', 'contacted', 'waiting_for_reply', 'qualified'].includes(request.status);
  const refusal = [assign, contact, note, followUp, move]
    .map((mutation) => mutation.error)
    .find((error) => error instanceof ApiError);

  return (
    <section
      aria-label={t('access.detail.work')}
      className="border-line bg-surface flex flex-col gap-4 rounded-2xl border p-4"
    >
      <h2 className="text-content text-sm font-semibold">{t('access.detail.work')}</h2>

      {refusal instanceof ApiError ? <Alert tone="danger">{refusal.message}</Alert> : null}

      <FilterSelect
        label={t('access.fields.owner')}
        value={request.owner_id}
        any={t('access.unassigned')}
        options={owners.map((owner) => ({ value: owner.id, label: owner.name }))}
        onChange={(value) => assign.mutate({ id: request.id, input: value })}
      />

      {open ? (
        <>
          <div className="flex flex-col gap-2">
            <Field label={t('access.contactNote')}>
              {(field) => (
                <Input
                  {...field}
                  value={contactNote}
                  maxLength={1000}
                  onChange={(event) => setContactNote(event.target.value)}
                />
              )}
            </Field>
            <div
              className="flex flex-wrap gap-2"
              role="group"
              aria-label={t('access.detail.record')}
            >
              {CONTACT_ACTIONS.map((action) => (
                <Button
                  key={action}
                  type="button"
                  size="sm"
                  variant="secondary"
                  disabled={contact.isPending}
                  onClick={() =>
                    contact.mutate(
                      { id: request.id, input: { action, note: contactNote } },
                      { onSuccess: () => setContactNote('') },
                    )
                  }
                >
                  {t(`access.actions.${action}`)}
                </Button>
              ))}
            </div>
            <p className="text-content-tertiary text-xs">
              {t('access.delivery', {
                email: t(`access.deliveryState.${delivery.email.state}`),
                whatsapp: t(`access.deliveryState.${delivery.whatsapp.state}`),
              })}
            </p>
          </div>

          <div className="flex flex-wrap items-end gap-2">
            <Field label={t('access.followUp')}>
              {(field) => (
                <Input
                  {...field}
                  type="datetime-local"
                  value={when}
                  onChange={(event) => setWhen(event.target.value)}
                />
              )}
            </Field>
            <Button
              type="button"
              size="sm"
              variant="secondary"
              disabled={when === '' || followUp.isPending}
              onClick={() =>
                followUp.mutate({ id: request.id, input: new Date(when).toISOString() })
              }
            >
              {t('access.actions.schedule')}
            </Button>
          </div>

          <div className="flex flex-wrap gap-2" role="group" aria-label={t('access.detail.move')}>
            {(['waiting_for_reply', 'qualified', 'closed'] as const)
              .filter((status) => status !== request.status)
              .map((status) => (
                <Button
                  key={status}
                  type="button"
                  size="sm"
                  variant="secondary"
                  disabled={move.isPending}
                  onClick={() => move.mutate({ id: request.id, input: status })}
                >
                  {t(`access.move.${status}`)}
                </Button>
              ))}
          </div>
        </>
      ) : null}

      <form
        className="flex flex-col gap-2"
        onSubmit={(event) => {
          event.preventDefault();
          note.mutate({ id: request.id, input: body }, { onSuccess: () => setBody('') });
        }}
      >
        <Field label={t('access.note')}>
          {(field) => (
            <Textarea
              {...field}
              rows={2}
              value={body}
              maxLength={2000}
              onChange={(event) => setBody(event.target.value)}
            />
          )}
        </Field>
        <Button
          type="submit"
          size="sm"
          variant="secondary"
          disabled={body.trim() === '' || note.isPending}
          className="self-start"
        >
          {t('access.actions.addNote')}
        </Button>
      </form>
    </section>
  );
}

function DecisionPanel({ request }: { request: AccessDetail }) {
  const { t } = useTranslation();
  const approve = useApproveMutation();
  const reject = useRejectMutation();
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');
  const [message, setMessage] = useState('');
  const open = ['new', 'contacted', 'waiting_for_reply', 'qualified'].includes(request.status);
  const refusal = [approve, reject].map((m) => m.error).find((error) => error instanceof ApiError);

  if (!open && request.status !== 'approved' && request.status !== 'rejected') {
    return null;
  }

  return (
    <section
      aria-label={t('access.detail.decision')}
      className="border-line bg-surface flex flex-col gap-3 rounded-2xl border p-4"
    >
      <h2 className="text-content text-sm font-semibold">{t('access.detail.decision')}</h2>
      {refusal instanceof ApiError ? <Alert tone="danger">{refusal.message}</Alert> : null}

      {request.status === 'rejected' ? (
        <div className="text-sm">
          <p>
            <span className="text-content-tertiary">{t('access.reason')}: </span>
            <span dir="auto">{request.review_note}</span>
          </p>
          {request.client_message === null ? null : (
            <p>
              <span className="text-content-tertiary">{t('access.messageForPerson')}: </span>
              <span dir="auto">{request.client_message}</span>
            </p>
          )}
          <p className="text-content-tertiary mt-1 text-xs">{t('access.reasonInternal')}</p>
        </div>
      ) : null}

      {request.status === 'approved' ? <InvitationPanel request={request} /> : null}

      {open ? (
        <>
          {request.kind === 'account_access' ? (
            <div className="flex flex-col gap-1">
              <Button
                type="button"
                className="self-start"
                disabled={approve.isPending}
                onClick={() => approve.mutate({ id: request.id, input: '' })}
              >
                {t('access.actions.approve')}
              </Button>
              <p className="text-content-tertiary text-xs">{t('access.approveNote')}</p>
            </div>
          ) : null}

          {rejecting ? (
            <form
              className="flex flex-col gap-2"
              onSubmit={(event) => {
                event.preventDefault();
                reject.mutate({ id: request.id, input: { reason, message } });
              }}
            >
              <Field label={t('access.reason')} required hint={t('access.reasonInternal')}>
                {(field) => (
                  <Textarea
                    {...field}
                    rows={2}
                    required
                    value={reason}
                    maxLength={1000}
                    onChange={(event) => setReason(event.target.value)}
                  />
                )}
              </Field>
              <Field label={t('access.messageForPerson')} hint={t('access.messageNotSent')}>
                {(field) => (
                  <Textarea
                    {...field}
                    rows={2}
                    value={message}
                    maxLength={1000}
                    onChange={(event) => setMessage(event.target.value)}
                  />
                )}
              </Field>
              <div className="flex gap-2">
                <Button
                  type="submit"
                  variant="secondary"
                  disabled={reason.trim() === '' || reject.isPending}
                >
                  {t('access.actions.confirmReject')}
                </Button>
                <Button type="button" variant="ghost" onClick={() => setRejecting(false)}>
                  {t('access.actions.cancel')}
                </Button>
              </div>
            </form>
          ) : (
            <Button
              type="button"
              variant="secondary"
              className="self-start"
              onClick={() => setRejecting(true)}
            >
              {t('access.actions.reject')}
            </Button>
          )}
        </>
      ) : null}
    </section>
  );
}

/**
 * Approval is a decision; an invitation is a separate act with a role and a scope. The link is shown once, because only
 * its hash is kept, and nothing here sends it — it says so, and "shared" is whatever a person says they did.
 */
function InvitationPanel({ request }: { request: AccessDetail }) {
  const { t, i18n } = useTranslation();
  const invite = useInviteMutation();
  const shared = useSharedMutation();
  const projects = useProjectsQuery({ per_page: 100 });
  const [role, setRole] = useState<string>('media-buyer');
  const [selected, setSelected] = useState<string[]>([]);
  const link = invite.data === undefined ? null : `${window.location.origin}${invite.data.path}`;
  const refusal = [invite, shared].map((m) => m.error).find((error) => error instanceof ApiError);

  if (request.account_created) {
    return <p className="text-sm">{t('access.invitation.done')}</p>;
  }

  return (
    <div className="flex flex-col gap-3">
      {refusal instanceof ApiError ? <Alert tone="danger">{refusal.message}</Alert> : null}

      <form
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault();
          invite.mutate({ id: request.id, role, projectIds: selected });
        }}
      >
        <FilterSelect
          label={t('access.invitation.role')}
          value={role}
          any={null}
          options={INVITABLE_ROLES.map((slug) => ({
            value: slug,
            label: t(`access.roles.${slug}`),
          }))}
          onChange={(value) => setRole(value ?? 'media-buyer')}
        />

        <fieldset className="flex min-w-0 flex-col gap-1">
          <legend className="text-content-secondary text-xs font-medium">
            {t('access.invitation.projects')}
          </legend>
          {(projects.data?.data ?? []).length === 0 ? (
            <p className="text-content-tertiary text-xs">{t('access.invitation.noProjects')}</p>
          ) : (
            <ul className="flex max-h-40 flex-col gap-1 overflow-y-auto">
              {(projects.data?.data ?? []).map((project) => (
                <li key={project.id}>
                  <label className="flex min-w-0 items-center gap-2 text-sm pointer-coarse:min-h-11">
                    <input
                      type="checkbox"
                      checked={selected.includes(project.id)}
                      onChange={(event) =>
                        setSelected((current) =>
                          event.target.checked
                            ? [...current, project.id]
                            : current.filter((entry) => entry !== project.id),
                        )
                      }
                    />
                    <span dir="auto" className="min-w-0 truncate">
                      {project.client.name} · {project.name}
                    </span>
                  </label>
                </li>
              ))}
            </ul>
          )}
        </fieldset>

        <Button type="submit" className="self-start" disabled={invite.isPending}>
          {request.invited_at === null
            ? t('access.invitation.create')
            : t('access.invitation.recreate')}
        </Button>
      </form>

      {link === null ? null : (
        <div className="bg-surface-sunken flex flex-col gap-2 rounded-xl p-3" role="status">
          <p className="text-sm font-medium">{t('access.invitation.once')}</p>
          <code dir="ltr" className="text-xs break-all" data-testid="invitation-link">
            {link}
          </code>
          <div className="flex flex-wrap gap-2">
            <CopyLink
              value={link}
              label={t('access.invitation.copy')}
              done={t('access.invitation.copied')}
            />
            <Button
              type="button"
              size="sm"
              variant="secondary"
              disabled={shared.isPending}
              onClick={() => shared.mutate({ id: request.id, input: null })}
            >
              {t('access.invitation.markShared')}
            </Button>
          </div>
          <p className="text-content-tertiary text-xs">{t('access.invitation.awaiting')}</p>
        </div>
      )}

      {request.invitation !== null ? (
        <p className="text-content-tertiary text-xs">
          {t('access.invitation.expires', {
            when: formatRelativeTime(request.invitation.expires_at, i18n.language),
          })}
        </p>
      ) : null}
    </div>
  );
}

/** What has happened to the person since the answer, each with the time it was evidenced — and a dash where it has not. */
function Milestones({ request }: { request: AccessDetail }) {
  const { t, i18n } = useTranslation();

  if (request.kind !== 'account_access') {
    return null;
  }

  const steps: Array<[string, string | null]> = [
    [
      'approved',
      request.status === 'approved' || request.invited_at !== null ? request.reviewed_at : null,
    ],
    ['invited', request.invited_at],
    ['shared', request.invitation_shared_at],
    ['opened', request.invitation_opened_at],
    ['accepted', request.accepted_at],
    ['account', request.account_created ? request.accepted_at : null],
    ['firstLogin', request.first_login_at],
  ];

  return (
    <section
      aria-label={t('access.milestones.title')}
      className="border-line bg-surface rounded-2xl border p-4"
    >
      <h2 className="text-content mb-2 text-sm font-semibold">{t('access.milestones.title')}</h2>
      <ol className="flex flex-col gap-1.5 text-sm">
        {steps.map(([key, at]) => (
          <li key={key} className="flex items-center justify-between gap-3">
            <span className={at === null ? 'text-content-tertiary' : 'text-content'}>
              {t(`access.milestones.${key}`)}
            </span>
            <span className="text-content-secondary text-xs">
              {at === null ? '—' : formatRelativeTime(at, i18n.language)}
            </span>
          </li>
        ))}
      </ol>
      <p className="text-content-tertiary mt-2 text-xs">{t('access.milestones.note')}</p>
    </section>
  );
}

function Timeline({ request }: { request: AccessDetail }) {
  const { t, i18n } = useTranslation();

  return (
    <section
      aria-label={t('access.detail.timeline')}
      className="border-line bg-surface rounded-2xl border p-4"
    >
      <h2 className="text-content mb-3 text-sm font-semibold">{t('access.detail.timeline')}</h2>
      <ol className="flex flex-col gap-3">
        {[...request.events].reverse().map((event) => (
          <li key={event.id} className="text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <span className="text-content font-medium">{t(`access.event.${event.type}`)}</span>
              <span className="text-content-tertiary text-xs">
                {formatRelativeTime(event.occurred_at, i18n.language)}
                {event.actor_name === null ? '' : ` · ${event.actor_name}`}
              </span>
            </div>
            {event.body === null ? null : (
              <p dir="auto" className="text-content-secondary whitespace-pre-wrap">
                {event.body}
              </p>
            )}
          </li>
        ))}
      </ol>
    </section>
  );
}
