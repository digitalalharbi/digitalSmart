import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card, CardContent } from '@/shared/ui/card';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import {
  useAddRecipientMutation,
  useChangeRecipientMutation,
  useRecipientsQuery,
  type RecipientDraft,
  type ReportRecipient,
} from './recipients';

const EMPTY: RecipientDraft = {
  email: '',
  name: '',
  locale: 'ar',
  cadence: 'weekly',
  consent_note: '',
};

const SELECT =
  'border-line bg-surface text-content h-10 rounded-lg border px-3 text-base sm:text-sm';

/**
 * The people outside the agency who are emailed this report's link, and what happened to each email.
 *
 * Two things are said before anything else. Whether this installation can send email at all — a list of recipients
 * over a server with no mail provider is a list of people who are receiving nothing, and the panel must not look
 * otherwise. And, per period, what the provider said: *accepted* is the mail server taking the message, which is not
 * the same as it arriving, so the word "delivered" does not appear here.
 */
export function RecipientsPanel({ reportId }: { reportId: string }) {
  const { t } = useTranslation();
  const list = useRecipientsQuery(reportId, true);
  const add = useAddRecipientMutation(reportId);
  const change = useChangeRecipientMutation(reportId);
  const [draft, setDraft] = useState<RecipientDraft>(EMPTY);

  const ready = draft.email.trim() !== '' && draft.consent_note.trim().length >= 10;
  const set = <K extends keyof RecipientDraft>(field: K, value: RecipientDraft[K]) =>
    setDraft((current) => ({ ...current, [field]: value }));

  return (
    <Card>
      <CardContent className="flex flex-col gap-4 p-5">
        <div>
          <h3 className="text-content text-base font-semibold">{t('reports.recipients.title')}</h3>
          <p className="text-content-secondary text-sm">{t('reports.recipients.intro')}</p>
        </div>

        {list.isError ? <Alert tone="danger">{t('reports.recipients.failed')}</Alert> : null}

        {list.data !== undefined && !list.data.mailConfigured ? (
          <Alert tone="warning">
            {t('reports.recipients.notConfigured')}
            {list.data.mailMissing === null ? null : (
              <span className="mt-1 block text-xs">{list.data.mailMissing}</span>
            )}
          </Alert>
        ) : null}

        {list.data !== undefined && list.data.recipients.length > 0 ? (
          <ul className="flex flex-col gap-3" aria-label={t('reports.recipients.title')}>
            {list.data.recipients.map((recipient) => (
              <RecipientRow
                key={recipient.id}
                recipient={recipient}
                isWorking={change.isPending}
                onChange={(action) => change.mutate({ id: recipient.id, action })}
              />
            ))}
          </ul>
        ) : list.data !== undefined ? (
          <p className="text-content-tertiary text-sm">{t('reports.recipients.none')}</p>
        ) : null}

        <form
          className="border-line-subtle flex flex-col gap-3 border-t pt-4"
          onSubmit={(event) => {
            event.preventDefault();
            add.mutate(draft, { onSuccess: () => setDraft(EMPTY) });
          }}
        >
          <div className="flex flex-wrap gap-3">
            <Field label={t('reports.recipients.email')} className="min-w-56 flex-1" required>
              {(props) => (
                <Input
                  {...props}
                  type="email"
                  autoComplete="off"
                  value={draft.email}
                  onChange={(event) => set('email', event.target.value)}
                />
              )}
            </Field>
            <Field label={t('reports.recipients.name')} className="min-w-40 flex-1">
              {(props) => (
                <Input
                  {...props}
                  value={draft.name}
                  onChange={(event) => set('name', event.target.value)}
                />
              )}
            </Field>
            <Field label={t('reports.recipients.cadence')}>
              {({ id }) => (
                <select
                  id={id}
                  className={SELECT}
                  value={draft.cadence}
                  onChange={(event) =>
                    set('cadence', event.target.value === 'monthly' ? 'monthly' : 'weekly')
                  }
                >
                  <option value="weekly">{t('reports.recipients.cadences.weekly')}</option>
                  <option value="monthly">{t('reports.recipients.cadences.monthly')}</option>
                </select>
              )}
            </Field>
            <Field label={t('reports.recipients.language')}>
              {({ id }) => (
                <select
                  id={id}
                  className={SELECT}
                  value={draft.locale}
                  onChange={(event) => set('locale', event.target.value === 'en' ? 'en' : 'ar')}
                >
                  <option value="ar">العربية</option>
                  <option value="en">English</option>
                </select>
              )}
            </Field>
          </div>

          <Field
            label={t('reports.recipients.consent')}
            hint={t('reports.recipients.consentHint')}
            required
          >
            {(props) => (
              <Input
                {...props}
                value={draft.consent_note}
                onChange={(event) => set('consent_note', event.target.value)}
              />
            )}
          </Field>

          {add.isError ? (
            <Alert tone="danger">
              {add.error instanceof ApiError
                ? add.error.message
                : t('reports.recipients.addFailed')}
            </Alert>
          ) : null}

          <div>
            <Button type="submit" disabled={!ready || add.isPending}>
              {t('reports.recipients.add')}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}

function RecipientRow({
  recipient,
  isWorking,
  onChange,
}: {
  recipient: ReportRecipient;
  isWorking: boolean;
  onChange: (action: 'pause' | 'resume' | 'remove') => void;
}) {
  const { t } = useTranslation();
  const last = recipient.deliveries[0];

  return (
    <li className="border-line-subtle flex flex-col gap-2 rounded-xl border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="min-w-0">
          <p className="text-content truncate text-sm font-medium" dir="ltr">
            {recipient.email}
          </p>
          <p className="text-content-tertiary text-xs">
            {t(`reports.recipients.cadences.${recipient.cadence}`)}
            {recipient.added_by_name === null
              ? ''
              : ` · ${t('reports.recipients.addedBy', { name: recipient.added_by_name })}`}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-1.5">
          <Badge tone={recipient.status === 'active' ? 'success' : 'warning'}>
            {t(`reports.recipients.status.${recipient.status}`)}
          </Badge>
          {recipient.status === 'active' ? (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={isWorking}
              onClick={() => onChange('pause')}
            >
              {t('reports.recipients.pause')}
            </Button>
          ) : recipient.status === 'paused' || recipient.status === 'halted' ? (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={isWorking}
              onClick={() => onChange('resume')}
            >
              {t('reports.recipients.resume')}
            </Button>
          ) : null}
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={isWorking}
            aria-label={t('reports.recipients.removeNamed', { email: recipient.email })}
            onClick={() => onChange('remove')}
          >
            {t('reports.recipients.remove')}
          </Button>
        </div>
      </div>

      <p className="text-content-secondary text-xs">
        {t('reports.recipients.consentRecorded', { note: recipient.consent_note })}
      </p>

      {recipient.halted_reason === null ? null : (
        <p className="text-warning text-xs">{recipient.halted_reason}</p>
      )}

      <p className="text-content-secondary text-xs" data-testid="recipient-last">
        {last === undefined
          ? t('reports.recipients.nothingYet')
          : t(`reports.recipients.delivery.${last.state}`, { period: last.period })}
      </p>
    </li>
  );
}
