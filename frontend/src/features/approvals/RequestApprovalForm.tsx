import { useEffect } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { useFieldArray, useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useTranslation } from 'react-i18next';
import { Plus, Trash2, X } from 'lucide-react';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Textarea } from '@/shared/ui/textarea';
import { useRequestApprovalMutation } from './useApprovals';
import type { ApprovalKindValue, ApprovalVocabulary } from './types';

/**
 * The three things the server refuses, refused here too so somebody reads them before a round trip.
 *
 * The proposal is the interesting one. It is free-form on purpose — this product does not model each platform's
 * schema to describe a change to it — so the form asks for keys and values rather than a shape, and requires at
 * least one. An approval with an empty proposal is a title somebody is asked to agree to.
 */
const schema = z.object({
  kind: z.string().min(1),
  title: z.string().min(3).max(200),
  rationale: z.string().min(1),
  client_id: z.string().optional(),
  proposal: z
    .array(z.object({ key: z.string(), value: z.string() }))
    .refine((rows) => rows.some((row) => row.key.trim() !== ''), { message: 'proposalRequired' }),
});

type FormValues = z.infer<typeof schema>;

type RequestApprovalFormProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  vocabulary: ApprovalVocabulary | undefined;
  clients: { id: string; name: string }[];
};

function defaults(): FormValues {
  return {
    kind: '',
    title: '',
    rationale: '',
    client_id: '',
    proposal: [{ key: '', value: '' }],
  };
}

export function RequestApprovalForm({
  open,
  onOpenChange,
  vocabulary,
  clients,
}: RequestApprovalFormProps) {
  const { t } = useTranslation();
  const ask = useRequestApprovalMutation();

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: defaults() });

  const rows = useFieldArray({ control, name: 'proposal' });

  useEffect(() => {
    if (open) {
      reset(defaults());
      ask.reset();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const kind = useWatch({ control, name: 'kind' });
  const chosen = vocabulary?.kinds.find((entry) => entry.kind === kind);

  const onSubmit = handleSubmit(async (values) => {
    /*
     * Values stay as they were typed. A form that guessed at numbers would send 0 for an empty field and a
     * decider would read a proposal nobody wrote; the fingerprint covers exactly these characters.
     */
    const proposal: Record<string, string> = {};

    for (const row of values.proposal) {
      const key = row.key.trim();

      if (key !== '') {
        proposal[key] = row.value.trim();
      }
    }

    try {
      await ask.mutateAsync({
        kind: values.kind as ApprovalKindValue,
        title: values.title.trim(),
        rationale: values.rationale.trim(),
        proposal,
        ...(values.client_id === undefined || values.client_id === ''
          ? {}
          : { clientId: values.client_id }),
      });
      onOpenChange(false);
    } catch {
      /* The server's own words are rendered below rather than thrown away. */
    }
  });

  const serverError = ask.error instanceof ApiError ? ask.error.message : null;

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40 duration-[var(--ds-duration-fast)]" />
        <Dialog.Content className="bg-surface animate-in fade-in zoom-in-95 fixed start-1/2 top-1/2 z-50 max-h-[90dvh] w-[calc(100vw-2rem)] max-w-xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl p-6 shadow-lg duration-[var(--ds-duration-base)] rtl:translate-x-1/2">
          <div className="mb-5 flex items-start justify-between gap-4">
            <div>
              <Dialog.Title className="text-content text-lg font-semibold">
                {t('approvals.form.title')}
              </Dialog.Title>
              <Dialog.Description className="text-content-secondary mt-1 text-sm">
                {t('approvals.form.description')}
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
            {serverError === null ? null : <Alert tone="danger">{serverError}</Alert>}

            <Field label={t('approvals.form.kind')} required error={errors.kind?.message}>
              {(field) => (
                <select
                  {...field}
                  {...register('kind')}
                  className="border-line bg-surface text-content h-9 w-full rounded-md border px-3 text-sm"
                >
                  <option value="">{t('approvals.form.selectKind')}</option>
                  {(vocabulary?.kinds ?? []).map((kind) => (
                    <option key={kind.kind} value={kind.kind}>
                      {kind.label}
                    </option>
                  ))}
                </select>
              )}
            </Field>

            {/*
              Said at the moment of asking, not only at the moment of deciding. Somebody raising a request for a
              change to live spend should know that approving it changes nothing on the platform by itself, and
              that this kind needs a second permission to decide.
            */}
            {chosen === undefined ? null : (
              <Alert tone="info" className="flex flex-col gap-1">
                {chosen.touches_live_spend ? (
                  <span className="flex items-center gap-2">
                    <Badge tone="warning">{t('approvals.form.touchesSpend')}</Badge>
                  </span>
                ) : null}
                <span className="text-xs">{chosen.enactment_blocked_because}</span>
              </Alert>
            )}

            <Field label={t('approvals.form.titleField')} required error={errors.title?.message}>
              {(field) => <Input {...field} {...register('title')} />}
            </Field>

            <Field
              label={t('approvals.form.rationale')}
              required
              error={errors.rationale?.message}
              hint={t('approvals.form.rationaleHint')}
            >
              {(field) => <Textarea {...field} {...register('rationale')} rows={3} />}
            </Field>

            <Field label={t('approvals.form.client')} error={errors.client_id?.message}>
              {(field) => (
                <select
                  {...field}
                  {...register('client_id')}
                  className="border-line bg-surface text-content h-9 w-full rounded-md border px-3 text-sm"
                >
                  <option value="">{t('approvals.form.noClient')}</option>
                  {clients.map((client) => (
                    <option key={client.id} value={client.id}>
                      {client.name}
                    </option>
                  ))}
                </select>
              )}
            </Field>

            <fieldset className="flex flex-col gap-2">
              <legend className="text-content text-sm font-medium">
                {t('approvals.form.proposal')}
              </legend>
              <p className="text-content-tertiary text-xs">{t('approvals.form.proposalHint')}</p>

              {rows.fields.map((row, index) => (
                <div key={row.id} className="flex items-end gap-2">
                  <Field label={t('approvals.form.proposalKey')} className="flex-1">
                    {(field) => (
                      <Input
                        {...field}
                        {...register(`proposal.${index}.key`)}
                        placeholder="external_campaign_id"
                      />
                    )}
                  </Field>
                  <Field label={t('approvals.form.proposalValue')} className="flex-1">
                    {(field) => <Input {...field} {...register(`proposal.${index}.value`)} />}
                  </Field>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={t('approvals.form.removeField')}
                    disabled={rows.fields.length === 1}
                    onClick={() => rows.remove(index)}
                  >
                    <Trash2 />
                  </Button>
                </div>
              ))}

              {errors.proposal?.root?.message === 'proposalRequired' ? (
                <p className="text-danger text-xs">{t('approvals.form.proposalRequired')}</p>
              ) : null}

              <Button
                type="button"
                variant="secondary"
                size="sm"
                className="self-start"
                onClick={() => rows.append({ key: '', value: '' })}
              >
                <Plus aria-hidden className="me-1 size-3.5" />
                {t('approvals.form.addField')}
              </Button>
            </fieldset>

            <div className="mt-2 flex justify-end gap-2">
              <Dialog.Close asChild>
                <Button variant="secondary">{t('actions.cancel')}</Button>
              </Dialog.Close>
              <Button type="submit" disabled={isSubmitting || ask.isPending}>
                {ask.isPending ? t('actions.saving') : t('approvals.form.submit')}
              </Button>
            </div>
          </form>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
