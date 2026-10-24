import { useState, type FormEvent } from 'react';
import { Link, useLocation, useSearchParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { CircleCheck } from 'lucide-react';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Textarea } from '@/shared/ui/textarea';
import { Logo } from '@/shared/ui/logo';
import { cn } from '@/shared/lib/cn';
import {
  ACCESS_NEEDS,
  PREFERRED_CONTACTS,
  REQUEST_KINDS,
  useAccessOpenQuery,
  useSubmitAccessRequest,
  type AccessNeed,
  type PreferredContact,
  type RequestKind,
} from './api';
import { NeedPicker } from './NeedPicker';
import { PreferenceSwitches } from './PreferenceSwitches';

/**
 * Asking for an account.
 *
 * A request, not a registration: nothing is created and nothing is sent. The page says so in plain words — a person
 * who is told "your account is ready" and then finds it is not has been misled by the one screen that made a promise.
 */
export function RequestAccessPage() {
  const { t, i18n } = useTranslation();
  const [params] = useSearchParams();
  const location = useLocation();
  /* `/contact` is the same form opened as a question; `/request-access` is the request for an account. */
  const contacting = location.pathname === '/contact';
  const open = useAccessOpenQuery();
  const submit = useSubmitAccessRequest();

  const asked = params.get('need');
  const [need, setNeed] = useState<AccessNeed>(
    ACCESS_NEEDS.find((entry) => entry === asked) ?? 'self_managed',
  );
  const [kind, setKind] = useState<RequestKind>(contacting ? 'general_enquiry' : 'account_access');
  const [preferred, setPreferred] = useState<PreferredContact>('email');
  const [jobTitle, setJobTitle] = useState('');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [company, setCompany] = useState('');
  const [phone, setPhone] = useState('');
  const [message, setMessage] = useState('');
  const [website, setWebsite] = useState('');

  const refusal =
    submit.error instanceof ApiError && submit.error.status === 422 ? submit.error.message : null;
  const failed = submit.isError && refusal === null;

  const onSubmit = (event: FormEvent): void => {
    event.preventDefault();

    submit.mutate({
      kind,
      jobTitle,
      preferredContact: preferred,
      name,
      email,
      company,
      phone,
      need,
      message,
      website,
      locale: i18n.language === 'en' ? 'en' : 'ar',
    });
  };

  return (
    <div className="bg-canvas text-content ds-safe-area flex min-h-dvh flex-col">
      <header className="border-line flex h-16 shrink-0 items-center justify-between gap-4 border-b px-4 sm:px-8">
        <Link
          to="/"
          aria-label={t('brand.name')}
          className="flex items-center pointer-coarse:min-h-11"
        >
          <Logo layout="horizontal" width={128} label={null} />
        </Link>
        <div className="flex items-center gap-2">
          <PreferenceSwitches />
          <Link
            to="/login"
            className={cn(
              buttonVariants({ variant: 'secondary', size: 'sm' }),
              'pointer-coarse:h-11',
            )}
          >
            {t('auth.signIn')}
          </Link>
        </div>
      </header>

      <main className="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center gap-6 px-4 py-8 sm:px-6">
        {open.isPending ? null : open.data !== true ? (
          <div className="flex flex-col gap-4">
            <h1 className="text-2xl font-bold">{t('entry.request.closedTitle')}</h1>
            <p className="text-content-secondary text-sm">{t('entry.request.closedBody')}</p>
            <Link to="/login" className={cn(buttonVariants({ size: 'lg' }), 'self-start')}>
              {t('auth.signIn')}
            </Link>
          </div>
        ) : submit.isSuccess ? (
          <div className="flex flex-col items-start gap-4" role="status">
            <CircleCheck aria-hidden className="text-success size-10" />
            <h1 className="text-2xl font-bold">{t('entry.request.doneTitle')}</h1>
            <p className="text-content-secondary text-sm">{t('entry.request.doneBody')}</p>
            <Link to="/" className={buttonVariants({ variant: 'secondary', size: 'lg' })}>
              {t('entry.request.back')}
            </Link>
          </div>
        ) : (
          <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate={false}>
            <div className="flex flex-col gap-1.5">
              <h1 className="text-2xl font-bold">
                {contacting ? t('entry.request.contactTitle') : t('entry.request.title')}
              </h1>
              <p className="text-content-secondary text-sm">
                {contacting ? t('entry.request.contactSubtitle') : t('entry.request.subtitle')}
              </p>
            </div>

            {refusal === null ? null : <Alert tone="danger">{refusal}</Alert>}
            {failed ? <Alert tone="danger">{t('entry.request.failed')}</Alert> : null}

            <Field label={t('entry.request.kind')}>
              {(field) => (
                <select
                  {...field}
                  value={kind}
                  onChange={(event) => setKind(event.target.value as RequestKind)}
                  className="border-line bg-surface text-content h-11 w-full min-w-0 truncate rounded-xl border px-3 text-base"
                >
                  {REQUEST_KINDS.map((entry) => (
                    <option key={entry} value={entry}>
                      {t(`entry.request.kinds.${entry}`)}
                    </option>
                  ))}
                </select>
              )}
            </Field>

            {kind === 'account_access' ? (
              <NeedPicker value={need} onChange={setNeed} name={t('entry.request.need')} />
            ) : null}

            <Field label={t('entry.request.name')} required>
              {(field) => (
                <Input
                  {...field}
                  value={name}
                  onChange={(event) => setName(event.target.value)}
                  autoComplete="name"
                  required
                  minLength={2}
                  maxLength={120}
                />
              )}
            </Field>
            <Field label={t('auth.email')} required>
              {(field) => (
                <Input
                  {...field}
                  type="email"
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                  autoComplete="email"
                  required
                  maxLength={190}
                />
              )}
            </Field>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label={t('entry.request.company')}>
                {(field) => (
                  <Input
                    {...field}
                    value={company}
                    onChange={(event) => setCompany(event.target.value)}
                    autoComplete="organization"
                    maxLength={160}
                  />
                )}
              </Field>
              <Field label={t('entry.request.phone')}>
                {(field) => (
                  <Input
                    {...field}
                    type="tel"
                    value={phone}
                    onChange={(event) => setPhone(event.target.value)}
                    autoComplete="tel"
                    maxLength={40}
                  />
                )}
              </Field>
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label={t('entry.request.jobTitle')}>
                {(field) => (
                  <Input
                    {...field}
                    value={jobTitle}
                    onChange={(event) => setJobTitle(event.target.value)}
                    autoComplete="organization-title"
                    maxLength={120}
                  />
                )}
              </Field>
              <Field label={t('entry.request.preferred')}>
                {(field) => (
                  <select
                    {...field}
                    value={preferred}
                    onChange={(event) => setPreferred(event.target.value as PreferredContact)}
                    className="border-line bg-surface text-content h-11 w-full min-w-0 truncate rounded-xl border px-3 text-base"
                  >
                    {PREFERRED_CONTACTS.map((entry) => (
                      <option key={entry} value={entry}>
                        {t(`entry.request.contacts.${entry}`)}
                      </option>
                    ))}
                  </select>
                )}
              </Field>
            </div>
            <Field label={t('entry.request.message')}>
              {(field) => (
                <Textarea
                  {...field}
                  rows={3}
                  value={message}
                  onChange={(event) => setMessage(event.target.value)}
                  maxLength={1000}
                />
              )}
            </Field>

            {/* A field no person sees or reaches. A script that fills every box fills it too. */}
            <div aria-hidden className="absolute -start-[9999px] h-0 w-0 overflow-hidden">
              <label>
                Website
                <input
                  tabIndex={-1}
                  autoComplete="off"
                  value={website}
                  onChange={(event) => setWebsite(event.target.value)}
                />
              </label>
            </div>

            <Button type="submit" size="lg" disabled={submit.isPending}>
              {submit.isPending ? t('entry.request.sending') : t('entry.request.send')}
            </Button>
            <p className="text-content-tertiary text-xs">{t('entry.request.promise')}</p>
          </form>
        )}
      </main>
    </div>
  );
}
