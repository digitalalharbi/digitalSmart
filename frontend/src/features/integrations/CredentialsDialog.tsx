import { useState } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { useTranslation } from 'react-i18next';
import { ShieldCheck, X } from 'lucide-react';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { useConfigureConnectionMutation } from './api';
import type { ProviderRow } from './types';

type CredentialsDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  provider: ProviderRow;
};

/**
 * Collects a provider's own API credentials — never a client's password.
 *
 * Values are write-only: the server returns the key names it holds and never
 * the secrets, so this form always starts empty, even for a configured
 * provider.
 */
export function CredentialsDialog({ open, onOpenChange, provider }: CredentialsDialogProps) {
  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      {/*
        Mounted only while open, and keyed by provider, so the form starts
        empty every time. Remounting is the honest way to reset state, rather
        than clearing it from an effect after the fact.
      */}
      {open ? (
        <CredentialsForm
          key={provider.provider}
          provider={provider}
          onDone={() => onOpenChange(false)}
        />
      ) : null}
    </Dialog.Root>
  );
}

function CredentialsForm({ provider, onDone }: { provider: ProviderRow; onDone: () => void }) {
  const { t } = useTranslation();
  const configure = useConfigureConnectionMutation();
  const keys =
    provider.missing_credentials.length > 0
      ? provider.missing_credentials
      : credentialKeysFor(provider);
  const [values, setValues] = useState<Record<string, string>>({});

  const onSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    configure.mutate({ provider: provider.provider, credentials: values }, { onSuccess: onDone });
  };

  const error = configure.error instanceof ApiError ? configure.error : null;
  const isComplete = keys.every((key) => (values[key] ?? '').trim().length > 0);

  return (
    <Dialog.Portal>
      <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40" />
      <Dialog.Content className="bg-surface animate-in fade-in zoom-in-95 fixed start-1/2 top-1/2 z-50 max-h-[90dvh] w-[calc(100vw-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl p-6 shadow-lg rtl:translate-x-1/2">
        <div className="mb-5 flex items-start justify-between gap-4">
          <div>
            <Dialog.Title className="text-content text-lg font-semibold tracking-[-0.01em]">
              {t('integrations.credentialsTitle', { provider: provider.label })}
            </Dialog.Title>
            <Dialog.Description className="text-content-secondary mt-1 text-sm">
              {t('integrations.credentialsDescription')}
            </Dialog.Description>
          </div>
          <Dialog.Close asChild>
            <Button variant="ghost" size="icon" aria-label={t('actions.close')}>
              <X />
            </Button>
          </Dialog.Close>
        </div>

        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          {error ? <Alert tone="danger">{error.message}</Alert> : null}

          <Alert tone="info" className="flex gap-2 text-xs">
            <ShieldCheck className="mt-px size-4 shrink-0" aria-hidden="true" />
            <span>{t('integrations.neverClientPassword')}</span>
          </Alert>

          {keys.map((key) => (
            <Field key={key} label={t(`integrations.credentialKeys.${key}`, key)} required>
              {(field) => (
                <Input
                  {...field}
                  type="password"
                  autoComplete="off"
                  spellCheck={false}
                  value={values[key] ?? ''}
                  onChange={(event) =>
                    setValues((current) => ({ ...current, [key]: event.target.value }))
                  }
                />
              )}
            </Field>
          ))}

          <p className="text-content-tertiary text-xs">{t('integrations.sandboxHint')}</p>

          <div className="mt-2 flex justify-end gap-2">
            <Dialog.Close asChild>
              <Button variant="secondary">{t('actions.cancel')}</Button>
            </Dialog.Close>
            <Button type="submit" disabled={!isComplete || configure.isPending}>
              {configure.isPending ? t('actions.saving') : t('actions.save')}
            </Button>
          </div>
        </form>
      </Dialog.Content>
    </Dialog.Portal>
  );
}

/**
 * Which fields to ask for when the server reports nothing missing.
 *
 * A configured provider returns an empty `missing_credentials`, but replacing
 * credentials still needs the full set — and the server never tells us the
 * values, only which keys it holds.
 */
function credentialKeysFor(provider: ProviderRow): string[] {
  const BY_PROVIDER: Record<string, string[]> = {
    meta: ['client_id', 'client_secret'],
    google_ads: ['client_id', 'client_secret', 'developer_token'],
    tiktok: ['app_id', 'app_secret'],
    snapchat: ['client_id', 'client_secret'],
    x: ['consumer_key', 'consumer_secret'],
    linkedin: ['client_id', 'client_secret'],
    openai: ['api_key'],
  };

  return BY_PROVIDER[provider.provider] ?? [];
}
