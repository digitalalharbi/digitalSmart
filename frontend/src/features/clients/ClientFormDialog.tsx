import { useEffect } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useTranslation } from 'react-i18next';
import { X } from 'lucide-react';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Textarea } from '@/shared/ui/textarea';
import { useCreateClientMutation, useUpdateClientMutation } from './api';
import type { Client, ClientPayload } from './types';

const schema = z.object({
  name: z.string().min(2).max(160),
  legal_name: z.string().max(200).optional(),
  industry: z.string().max(120).optional(),
  website: z.union([z.string().url(), z.literal('')]).optional(),
  primary_contact_name: z.string().max(160).optional(),
  primary_contact_email: z.union([z.string().email(), z.literal('')]).optional(),
  primary_contact_phone: z.string().max(40).optional(),
  default_currency: z.union([z.string().length(3), z.literal('')]).optional(),
  notes: z.string().max(5000).optional(),
});

type FormValues = z.infer<typeof schema>;

type ClientFormDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Absent means "create". */
  client?: Client | undefined;
};

/** Empty strings are sent as null so the API stores absence, not "". */
function toPayload(values: FormValues): ClientPayload {
  const clean = (value: string | undefined): string | null => {
    const trimmed = value?.trim();
    return trimmed ? trimmed : null;
  };

  return {
    name: values.name.trim(),
    legal_name: clean(values.legal_name),
    industry: clean(values.industry),
    website: clean(values.website),
    primary_contact_name: clean(values.primary_contact_name),
    primary_contact_email: clean(values.primary_contact_email),
    primary_contact_phone: clean(values.primary_contact_phone),
    default_currency: clean(values.default_currency)?.toUpperCase() ?? null,
    notes: clean(values.notes),
  };
}

function toDefaults(client: Client | undefined): FormValues {
  return {
    name: client?.name ?? '',
    legal_name: client?.legal_name ?? '',
    industry: client?.industry ?? '',
    website: client?.website ?? '',
    primary_contact_name: client?.primary_contact_name ?? '',
    primary_contact_email: client?.primary_contact_email ?? '',
    primary_contact_phone: client?.primary_contact_phone ?? '',
    default_currency: client?.default_currency ?? '',
    notes: client?.notes ?? '',
  };
}

export function ClientFormDialog({ open, onOpenChange, client }: ClientFormDialogProps) {
  const { t } = useTranslation();
  const isEditing = client !== undefined;
  const create = useCreateClientMutation();
  const update = useUpdateClientMutation();
  const mutation = isEditing ? update : create;

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: toDefaults(client),
  });

  // Re-seed when the dialog opens for a different client.
  useEffect(() => {
    if (open) {
      reset(toDefaults(client));
      mutation.reset();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, client?.id]);

  const onSubmit = handleSubmit(async (values) => {
    const payload = toPayload(values);

    try {
      if (isEditing) {
        await update.mutateAsync({ id: client.id, payload });
      } else {
        await create.mutateAsync(payload);
      }
      onOpenChange(false);
    } catch (error) {
      // Server-side validation is authoritative; map it back onto the fields.
      if (error instanceof ApiError) {
        for (const [field, messages] of Object.entries(error.errors)) {
          if (field in schema.shape && messages[0]) {
            setError(field as keyof FormValues, { message: messages[0] });
          }
        }
      }
    }
  });

  const serverError =
    mutation.error instanceof ApiError && Object.keys(mutation.error.errors).length === 0
      ? mutation.error.message
      : null;

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40 duration-[var(--ds-duration-fast)]" />
        <Dialog.Content className="bg-surface animate-in fade-in zoom-in-95 fixed start-1/2 top-1/2 z-50 max-h-[90dvh] w-[calc(100vw-2rem)] max-w-xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl p-6 shadow-lg duration-[var(--ds-duration-base)] rtl:translate-x-1/2">
          <div className="mb-5 flex items-start justify-between gap-4">
            <div>
              <Dialog.Title className="text-content text-lg font-semibold">
                {isEditing ? t('clients.form.editTitle') : t('clients.form.createTitle')}
              </Dialog.Title>
              <Dialog.Description className="text-content-secondary mt-1 text-sm">
                {t('clients.form.description')}
              </Dialog.Description>
            </div>
            <Dialog.Close asChild>
              <Button variant="ghost" size="icon" aria-label={t('actions.close')}>
                <X />
              </Button>
            </Dialog.Close>
          </div>

          <form
            onSubmit={(event) => void onSubmit(event)}
            noValidate
            className="flex flex-col gap-4"
          >
            {serverError ? <Alert tone="danger">{serverError}</Alert> : null}

            <Field label={t('clients.fields.name')} required error={errors.name?.message}>
              {(field) => <Input {...field} {...register('name')} autoFocus />}
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={t('clients.fields.legalName')} error={errors.legal_name?.message}>
                {(field) => <Input {...field} {...register('legal_name')} />}
              </Field>
              <Field label={t('clients.fields.industry')} error={errors.industry?.message}>
                {(field) => <Input {...field} {...register('industry')} />}
              </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field
                label={t('clients.fields.website')}
                error={errors.website?.message}
                hint="https://example.com"
              >
                {(field) => <Input {...field} {...register('website')} inputMode="url" />}
              </Field>
              <Field
                label={t('clients.fields.currency')}
                error={errors.default_currency?.message}
                hint={t('clients.fields.currencyHint')}
              >
                {(field) => (
                  <Input
                    {...field}
                    {...register('default_currency')}
                    maxLength={3}
                    className="uppercase"
                  />
                )}
              </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field
                label={t('clients.fields.contactName')}
                error={errors.primary_contact_name?.message}
              >
                {(field) => <Input {...field} {...register('primary_contact_name')} />}
              </Field>
              <Field
                label={t('clients.fields.contactEmail')}
                error={errors.primary_contact_email?.message}
              >
                {(field) => (
                  <Input {...field} {...register('primary_contact_email')} type="email" />
                )}
              </Field>
            </div>

            <Field
              label={t('clients.fields.contactPhone')}
              error={errors.primary_contact_phone?.message}
            >
              {(field) => (
                <Input {...field} {...register('primary_contact_phone')} inputMode="tel" />
              )}
            </Field>

            <Field label={t('clients.fields.notes')} error={errors.notes?.message}>
              {(field) => <Textarea {...field} {...register('notes')} rows={3} />}
            </Field>

            <div className="mt-2 flex justify-end gap-2">
              <Dialog.Close asChild>
                <Button variant="secondary">{t('actions.cancel')}</Button>
              </Dialog.Close>
              <Button type="submit" disabled={isSubmitting || mutation.isPending}>
                {mutation.isPending ? t('actions.saving') : t('actions.save')}
              </Button>
            </div>
          </form>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
