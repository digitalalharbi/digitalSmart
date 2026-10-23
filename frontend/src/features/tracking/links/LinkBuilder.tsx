import * as Dialog from '@radix-ui/react-dialog';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useProjectsQuery } from '@/features/projects';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { returnFocus } from '@/shared/ui/return-focus';
import { useSaveLinkMutation } from './api';
import type { LinkDraft } from './types';

const PLATFORMS = ['snapchat', 'tiktok', 'meta', 'google_ads', 'x', 'linkedin'] as const;

/** The default naming: the platform, the campaign and the creative say what each parameter is without anybody typing it. */
const DEFAULT_PATTERNS = {
  utm_source: '{{platform}}',
  utm_medium: 'paid',
  utm_campaign: '{{campaign}}',
  utm_content: '{{creative}}',
};

export function LinkBuilder({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation();
  const save = useSaveLinkMutation();
  const [term, setTerm] = useState('');
  const [project, setProject] = useState<{ id: string; label: string } | null>(null);
  const [form, setForm] = useState({
    name: '',
    destination_url: '',
    platform: 'snapchat',
    campaign: '',
    ad_group: '',
    ad: '',
    creative: '',
  });
  const projects = useProjectsQuery({ search: term, per_page: 8 });
  const set = (key: keyof typeof form) => (value: string) =>
    setForm((f) => ({ ...f, [key]: value }));

  const submit = () => {
    if (project === null) {
      return;
    }

    const draft: LinkDraft = {
      project_id: project.id,
      name: form.name,
      destination_url: form.destination_url,
      platform: form.platform,
      template_patterns: DEFAULT_PATTERNS,
      ...(form.campaign ? { campaign: form.campaign } : {}),
      ...(form.ad_group ? { ad_group: form.ad_group } : {}),
      ...(form.ad ? { ad: form.ad } : {}),
      ...(form.creative ? { creative: form.creative } : {}),
    };

    save.mutate({ draft }, { onSuccess: () => onOpenChange(false) });
  };

  return (
    <Dialog.Root
      open={open}
      onOpenChange={(next) => {
        if (!next) {
          save.reset();
        }
        onOpenChange(next);
      }}
    >
      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40 duration-[var(--ds-duration-fast)]" />
        <Dialog.Content
          onCloseAutoFocus={returnFocus}
          className="bg-surface animate-in fade-in zoom-in-95 fixed start-1/2 top-1/2 z-50 max-h-[92dvh] w-[calc(100vw-2rem)] max-w-xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl p-5 shadow-lg duration-[var(--ds-duration-base)] sm:p-6 rtl:translate-x-1/2"
        >
          <Dialog.Title className="text-content text-lg font-semibold">
            {t('tracking.links.builder.title')}
          </Dialog.Title>
          <Dialog.Description className="text-content-secondary mt-1 text-sm">
            {t('tracking.links.builder.body')}
          </Dialog.Description>

          <form
            className="mt-4 flex flex-col gap-4"
            onSubmit={(event) => {
              event.preventDefault();
              submit();
            }}
          >
            <EntityPicker
              label={t('tracking.links.builder.project')}
              clearLabel={t('actions.clear')}
              value={project}
              onChange={(option) =>
                setProject(option === null ? null : { id: option.id, label: option.label })
              }
              onSearch={setTerm}
              options={(projects.data?.data ?? []).map((p) => ({
                id: p.id,
                label: p.name,
                context: p.client.name,
              }))}
              isLoading={projects.isFetching}
            />
            <Field label={t('tracking.links.builder.name')} required>
              {(f) => (
                <Input
                  {...f}
                  value={form.name}
                  onChange={(e) => set('name')(e.target.value)}
                  maxLength={160}
                  required
                />
              )}
            </Field>
            <Field
              label={t('tracking.links.builder.destination')}
              hint={t('tracking.links.builder.destinationHint')}
              required
            >
              {(f) => (
                <Input
                  {...f}
                  type="url"
                  inputMode="url"
                  dir="ltr"
                  value={form.destination_url}
                  onChange={(e) => set('destination_url')(e.target.value)}
                  placeholder="https://"
                  required
                />
              )}
            </Field>
            <Field label={t('tracking.links.builder.platform')}>
              {(f) => (
                <select
                  {...f}
                  className="border-line bg-surface text-content h-11 rounded-lg border px-3 text-[16px] md:h-10 md:text-sm"
                  value={form.platform}
                  onChange={(e) => set('platform')(e.target.value)}
                >
                  {PLATFORMS.map((p) => (
                    <option key={p} value={p}>
                      {t(`tracking.links.platforms.${p}`)}
                    </option>
                  ))}
                </select>
              )}
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={t('tracking.links.builder.campaign')}>
                {(f) => (
                  <Input
                    {...f}
                    value={form.campaign}
                    onChange={(e) => set('campaign')(e.target.value)}
                  />
                )}
              </Field>
              <Field label={t('tracking.links.builder.creative')}>
                {(f) => (
                  <Input
                    {...f}
                    value={form.creative}
                    onChange={(e) => set('creative')(e.target.value)}
                  />
                )}
              </Field>
              <Field label={t('tracking.links.builder.adGroup')}>
                {(f) => (
                  <Input
                    {...f}
                    value={form.ad_group}
                    onChange={(e) => set('ad_group')(e.target.value)}
                  />
                )}
              </Field>
              <Field label={t('tracking.links.builder.ad')}>
                {(f) => (
                  <Input {...f} value={form.ad} onChange={(e) => set('ad')(e.target.value)} />
                )}
              </Field>
            </div>
            <p className="text-content-tertiary text-xs">{t('tracking.links.builder.utmNote')}</p>

            {save.isError ? (
              <Alert tone="danger">
                {save.error instanceof ApiError && save.error.message !== ''
                  ? save.error.message
                  : t('tracking.links.builder.failed')}
              </Alert>
            ) : null}

            <div className="flex justify-end gap-2">
              <Dialog.Close asChild>
                <Button type="button" variant="secondary">
                  {t('actions.cancel')}
                </Button>
              </Dialog.Close>
              <Button type="submit" disabled={save.isPending || project === null}>
                {t('tracking.links.builder.create')}
              </Button>
            </div>
          </form>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
