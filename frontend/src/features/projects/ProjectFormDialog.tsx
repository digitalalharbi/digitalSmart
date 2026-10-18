import { useEffect, useState } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { useForm, useWatch } from 'react-hook-form';
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
import { EntityPicker } from '@/shared/ui/entity-picker';
import { useClientsQuery } from '@/features/clients';
import { useCreateProjectMutation, useUpdateProjectMutation } from './api';
import type { Project, ProjectPayload } from './types';
import { returnFocus } from '@/shared/ui/return-focus';

const schema = z
  .object({
    client_id: z.string().min(1),
    name: z.string().min(2).max(160),
    currency: z.string().length(3),
    description: z.string().max(5000).optional(),
    starts_on: z.string().optional(),
    ends_on: z.string().optional(),
  })
  .refine((values) => !values.starts_on || !values.ends_on || values.ends_on >= values.starts_on, {
    path: ['ends_on'],
    message: 'endsBeforeStart',
  });

type FormValues = z.infer<typeof schema>;

type ProjectFormDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  project?: Project | undefined;
};

function toDefaults(project: Project | undefined): FormValues {
  return {
    client_id: project?.client.id ?? '',
    name: project?.name ?? '',
    currency: project?.currency ?? 'SAR',
    description: project?.description ?? '',
    starts_on: project?.starts_on ?? '',
    ends_on: project?.ends_on ?? '',
  };
}

export function ProjectFormDialog({ open, onOpenChange, project }: ProjectFormDialogProps) {
  const { t } = useTranslation();
  const isEditing = project !== undefined;
  const create = useCreateProjectMutation();
  const update = useUpdateProjectMutation();
  const mutation = isEditing ? update : create;

  // Only the clients the viewer may see can be chosen as a parent.
  const [clientTerm, setClientTerm] = useState('');
  const [clientLabel, setClientLabel] = useState('');
  const { data: clients, isFetching } = useClientsQuery({ search: clientTerm, per_page: 8 });

  const {
    register,
    handleSubmit,
    reset,
    setError,
    setValue,
    control,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: toDefaults(project) });

  const watchedClient = useWatch({ control, name: 'client_id' });
  const chosenClient = watchedClient === '' ? null : { id: watchedClient, label: clientLabel };

  useEffect(() => {
    if (open) {
      reset(toDefaults(project));
      mutation.reset();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, project?.id]);

  const onSubmit = handleSubmit(async (values) => {
    const payload: ProjectPayload = {
      name: values.name.trim(),
      currency: values.currency.toUpperCase(),
      description: values.description?.trim() || null,
      starts_on: values.starts_on || null,
      ends_on: values.ends_on || null,
    };

    try {
      if (isEditing) {
        await update.mutateAsync({ id: project.id, payload });
      } else {
        await create.mutateAsync({ ...payload, client_id: values.client_id });
      }
      onOpenChange(false);
    } catch (error) {
      if (error instanceof ApiError) {
        for (const [field, messages] of Object.entries(error.errors)) {
          if (messages[0]) {
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

  const endsError =
    errors.ends_on?.message === 'endsBeforeStart'
      ? t('projects.fields.endsBeforeStart')
      : errors.ends_on?.message;

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40 duration-[var(--ds-duration-fast)]" />
        <Dialog.Content
          onCloseAutoFocus={returnFocus}
          className="bg-surface animate-in fade-in zoom-in-95 fixed start-1/2 top-1/2 z-50 max-h-[90dvh] w-[calc(100vw-2rem)] max-w-xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl p-6 shadow-lg duration-[var(--ds-duration-base)] rtl:translate-x-1/2"
        >
          <div className="mb-5 flex items-start justify-between gap-4">
            <div>
              <Dialog.Title className="text-content text-lg font-semibold">
                {isEditing ? t('projects.form.editTitle') : t('projects.form.createTitle')}
              </Dialog.Title>
              <Dialog.Description className="text-content-secondary mt-1 text-sm">
                {t('projects.form.description')}
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

            {isEditing ? (
              <div className="flex flex-col gap-1">
                <span className="text-content text-sm font-medium">
                  {t('projects.fields.client')}
                </span>
                <p className="text-content text-sm">{project.client.name}</p>
                <p className="text-content-tertiary text-xs">{t('projects.fields.clientLocked')}</p>
              </div>
            ) : (
              <div className="flex flex-col gap-1">
                <EntityPicker
                  label={t('projects.fields.client')}
                  clearLabel={t('actions.clear')}
                  placeholder={t('projects.fields.selectClient')}
                  value={chosenClient}
                  onChange={(option) => {
                    setClientLabel(option?.label ?? '');
                    setValue('client_id', option?.id ?? '', { shouldValidate: true });
                  }}
                  onSearch={setClientTerm}
                  options={(clients?.data ?? []).map((c) => ({ id: c.id, label: c.name }))}
                  isLoading={isFetching}
                />
                {errors.client_id?.message === undefined ? null : (
                  <p role="alert" className="text-danger text-xs">
                    {errors.client_id.message}
                  </p>
                )}
              </div>
            )}

            <Field label={t('projects.fields.name')} required error={errors.name?.message}>
              {(field) => <Input {...field} {...register('name')} />}
            </Field>

            <div className="grid gap-4 sm:grid-cols-3">
              <Field
                label={t('projects.fields.currency')}
                required
                error={errors.currency?.message}
              >
                {(field) => (
                  <Input {...field} {...register('currency')} maxLength={3} className="uppercase" />
                )}
              </Field>
              <Field label={t('projects.fields.startsOn')} error={errors.starts_on?.message}>
                {(field) => <Input {...field} {...register('starts_on')} type="date" />}
              </Field>
              <Field label={t('projects.fields.endsOn')} error={endsError}>
                {(field) => <Input {...field} {...register('ends_on')} type="date" />}
              </Field>
            </div>

            <Field label={t('projects.fields.description')} error={errors.description?.message}>
              {(field) => <Textarea {...field} {...register('description')} rows={3} />}
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
