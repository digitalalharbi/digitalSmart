import { useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { CircleCheck } from 'lucide-react';
import { ApiError } from '@/shared/api/client';
import { cn } from '@/shared/lib/cn';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Logo } from '@/shared/ui/logo';
import { PreferenceSwitches } from '@/features/entry';
import { useAcceptInvitationMutation, useInvitationQuery } from './api';

/** Keep in step with the server's minimum (`ChangePassword::MINIMUM_LENGTH`). */
const MINIMUM_PASSWORD = 12;

/**
 * Where an invited person takes the invitation.
 *
 * Public, and it says the same for every link that is not a live invitation — unknown, expired, used, withdrawn — so it cannot be
 * used to ask which addresses were invited. The person chooses their own password here; nobody else ever knows it. No account
 * exists until this is submitted.
 */
export function InvitationPage() {
  const { t } = useTranslation();
  const { token = '' } = useParams();
  const preview = useInvitationQuery(token);
  const accept = useAcceptInvitationMutation(token);
  const [password, setPassword] = useState('');
  const [again, setAgain] = useState('');

  const needsPassword = preview.data?.valid === true && preview.data.needs_password;
  const mismatch = again !== '' && again !== password;
  const refusal =
    accept.error instanceof ApiError && accept.error.status === 422 ? accept.error.message : null;

  const onSubmit = (event: FormEvent): void => {
    event.preventDefault();

    if (needsPassword && (password.length < MINIMUM_PASSWORD || password !== again)) {
      return;
    }

    accept.mutate(needsPassword ? password : null);
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
        <PreferenceSwitches />
      </header>

      <main className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-6 px-4 py-8">
        {preview.isPending ? null : preview.isError ? (
          <Alert tone="danger">{t('access.accept.failed')}</Alert>
        ) : accept.isSuccess ? (
          <div className="flex flex-col items-start gap-4" role="status">
            <CircleCheck aria-hidden className="text-success size-10" />
            <h1 className="text-2xl font-bold">{t('access.accept.doneTitle')}</h1>
            <p className="text-content-secondary text-sm">{t('access.accept.doneBody')}</p>
            <Link to="/login" className={cn(buttonVariants({ size: 'lg' }), 'max-sm:w-full')}>
              {t('auth.signIn')}
            </Link>
          </div>
        ) : !preview.data.valid ? (
          <div className="flex flex-col gap-3">
            <h1 className="text-2xl font-bold">{t('access.accept.invalidTitle')}</h1>
            <p className="text-content-secondary text-sm">{t('access.accept.invalidBody')}</p>
            <Link
              to="/login"
              className={cn(buttonVariants({ variant: 'secondary' }), 'self-start')}
            >
              {t('auth.signIn')}
            </Link>
          </div>
        ) : (
          <form onSubmit={onSubmit} className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              <h1 className="text-2xl font-bold">{t('access.accept.title')}</h1>
              <p dir="auto" className="text-content-secondary text-sm">
                {t('access.accept.hello', { name: preview.data.name })}
              </p>
            </div>

            {refusal === null ? null : <Alert tone="danger">{refusal}</Alert>}
            {accept.isError && refusal === null ? (
              <Alert tone="danger">{t('access.accept.failed')}</Alert>
            ) : null}

            {needsPassword ? (
              <>
                <Field
                  label={t('access.accept.password')}
                  hint={t('access.accept.passwordHint', { count: MINIMUM_PASSWORD })}
                  required
                >
                  {(field) => (
                    <Input
                      {...field}
                      type="password"
                      autoComplete="new-password"
                      value={password}
                      minLength={MINIMUM_PASSWORD}
                      required
                      onChange={(event) => setPassword(event.target.value)}
                    />
                  )}
                </Field>
                <Field
                  label={t('access.accept.again')}
                  required
                  error={mismatch ? t('access.accept.mismatch') : undefined}
                >
                  {(field) => (
                    <Input
                      {...field}
                      type="password"
                      autoComplete="new-password"
                      value={again}
                      required
                      onChange={(event) => setAgain(event.target.value)}
                    />
                  )}
                </Field>
              </>
            ) : (
              <p className="text-content-secondary text-sm">{t('access.accept.existing')}</p>
            )}

            <Button type="submit" size="lg" disabled={accept.isPending || mismatch}>
              {accept.isPending ? t('access.accept.working') : t('access.accept.submit')}
            </Button>
          </form>
        )}
      </main>
    </div>
  );
}
