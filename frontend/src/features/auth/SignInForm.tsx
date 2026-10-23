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

/**
 * The sign-in form, wherever it is shown: the sign-in screen and the landing page.
 *
 * It is the only way in, so there is one of it. Focus is moved to the first field only where the form is the whole page;
 * on a landing page that would pull a phone's keyboard up over the headline before anybody had read it.
 */
export function SignInForm({ autoFocus = false }: { autoFocus?: boolean }) {
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
            autoFocus={autoFocus}
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

      <label className="text-content-secondary flex items-center gap-2 text-sm pointer-coarse:min-h-11">
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
  );
}
