import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { ApiError } from '@/shared/api/client';
import { useLoginMutation } from './api';

const schema = z.object({
  email: z.string().min(1).email(),
  password: z.string().min(1),
  remember: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

export function LoginPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const login = useLoginMutation();

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', password: '', remember: false },
  });

  const onSubmit = handleSubmit(async (values) => {
    await login.mutateAsync(values);
    void navigate('/', { replace: true });
  });

  // The server returns one message for both a wrong password and an unknown
  // address, on purpose; it is shown verbatim rather than being interpreted.
  const serverError = login.error instanceof ApiError ? login.error.message : null;

  return (
    <div className="bg-canvas flex min-h-dvh items-center justify-center px-4 py-10">
      <div className="w-full max-w-sm">
        <div className="mb-8 flex flex-col items-center gap-3 text-center">
          <span
            aria-hidden="true"
            className="bg-accent-strong text-accent-contrast grid size-11 place-items-center rounded-xl text-base font-bold"
          >
            dS
          </span>
          <div>
            <h1 className="text-content text-xl font-semibold">{t('auth.signInTitle')}</h1>
            <p className="text-content-secondary mt-1 text-sm">{t('auth.signInSubtitle')}</p>
          </div>
        </div>

        <form
          onSubmit={(event) => void onSubmit(event)}
          noValidate
          className="bg-surface border-line-subtle flex flex-col gap-4 rounded-2xl border p-6 shadow-sm"
        >
          {serverError ? <Alert tone="danger">{serverError}</Alert> : null}

          <Field
            label={t('auth.email')}
            required
            error={errors.email ? t('auth.emailInvalid') : undefined}
          >
            {(field) => (
              <Input
                {...field}
                {...register('email')}
                type="email"
                autoComplete="email"
                autoFocus
                placeholder="you@agency.com"
              />
            )}
          </Field>

          <Field
            label={t('auth.password')}
            required
            error={errors.password ? t('auth.passwordRequired') : undefined}
          >
            {(field) => (
              <Input
                {...field}
                {...register('password')}
                type="password"
                autoComplete="current-password"
              />
            )}
          </Field>

          <label className="text-content-secondary flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              {...register('remember')}
              className="size-4 rounded accent-[var(--ds-accent)]"
            />
            {t('auth.rememberMe')}
          </label>

          <Button type="submit" size="lg" disabled={isSubmitting || login.isPending}>
            {login.isPending ? t('auth.signingIn') : t('auth.signIn')}
          </Button>
        </form>
      </div>
    </div>
  );
}
