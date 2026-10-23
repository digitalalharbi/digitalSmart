import { Copy, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { useFormsQuery, useSetFormStatusMutation } from './api';
import { FormBuilder } from './FormBuilder';
import type { FormSummary } from './types';

function publicUrl(form: FormSummary): string {
  return `${window.location.origin}/f/${form.public_url_token}`;
}

function FormCard({
  form,
  canManage,
  onEdit,
}: {
  form: FormSummary;
  canManage: boolean;
  onEdit: () => void;
}) {
  const { t } = useTranslation();
  const status = useSetFormStatusMutation();
  const [copied, setCopied] = useState(false);
  const published = form.status === 'published';

  return (
    <Card role="group" aria-label={form.name} className="flex min-w-0 flex-col gap-3 p-4 sm:p-5">
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h3 className="text-content truncate text-base font-semibold">{form.name}</h3>
          <p className="text-content-tertiary truncate text-xs">
            {form.client.name} · {form.project.name}
          </p>
        </div>
        <div className="flex flex-wrap gap-1.5">
          <Badge tone={published ? 'success' : 'neutral'}>
            {t(`tracking.forms.status.${form.status}`)}
          </Badge>
          {form.creates_lead ? (
            <Badge tone="accent">{t('tracking.forms.createsLead')}</Badge>
          ) : null}
        </div>
      </header>

      <dl className="grid grid-cols-2 gap-3">
        <div>
          <dt className="text-content-tertiary text-xs">{t('tracking.forms.fields')}</dt>
          <dd className="text-content text-lg font-semibold tabular-nums">{form.fields.length}</dd>
        </div>
        <div>
          <dt className="text-content-tertiary text-xs">{t('tracking.forms.submissions')}</dt>
          <dd className="text-content text-lg font-semibold tabular-nums">
            {form.submissions?.value ?? t('tracking.links.notReported')}
          </dd>
        </div>
      </dl>

      {published ? (
        <div className="bg-surface-sunken flex items-center gap-2 rounded-lg px-3 py-2">
          <code dir="ltr" className="text-content min-w-0 flex-1 truncate text-xs">
            {publicUrl(form)}
          </code>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="max-md:h-11"
            aria-label={t('tracking.forms.copy', { name: form.name })}
            onClick={() => {
              void navigator.clipboard
                ?.writeText(publicUrl(form))
                .then(() => setCopied(true))
                .catch(() => setCopied(false));
            }}
          >
            <Copy aria-hidden="true" className="size-4" />
            <span className="sr-only sm:not-sr-only">
              {copied ? t('tracking.links.copied') : t('tracking.links.copyShort')}
            </span>
          </Button>
          <a
            href={`/f/${form.public_url_token}`}
            target="_blank"
            rel="noreferrer"
            aria-label={t('tracking.forms.open', { name: form.name })}
            className="text-content-secondary inline-flex size-11 items-center justify-center md:size-9"
          >
            <ExternalLink aria-hidden="true" className="size-4" />
          </a>
        </div>
      ) : null}

      {status.isError ? <Alert tone="danger">{t('tracking.forms.statusFailed')}</Alert> : null}

      {canManage ? (
        <div className="flex flex-wrap justify-end gap-2">
          <Button
            type="button"
            variant="secondary"
            size="sm"
            className="max-md:h-11"
            onClick={onEdit}
          >
            {t('tracking.forms.edit')}
          </Button>
          <Button
            type="button"
            size="sm"
            variant={published ? 'secondary' : 'primary'}
            className="max-md:h-11"
            disabled={status.isPending}
            onClick={() =>
              status.mutate({ id: form.id, status: published ? 'draft' : 'published' })
            }
          >
            {published ? t('tracking.forms.unpublish') : t('tracking.forms.publish')}
          </Button>
        </div>
      ) : null}
    </Card>
  );
}

/** The Forms tab: forms a project publishes, and the way in for a lead. */
export function FormsTab() {
  const { t } = useTranslation();
  const { can } = useSession();
  const forms = useFormsQuery();
  const [editing, setEditing] = useState<FormSummary | 'new' | null>(null);
  const canManage = can(PERMISSIONS.trackingManage);

  if (editing !== null) {
    return (
      <FormBuilder form={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-content-secondary max-w-prose text-sm">{t('tracking.forms.intro')}</p>
        {canManage ? (
          <Button type="button" onClick={() => setEditing('new')}>
            {t('tracking.forms.create')}
          </Button>
        ) : null}
      </div>

      {forms.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : forms.isError ? (
        <Alert tone="danger">{t('tracking.forms.failed')}</Alert>
      ) : forms.data.length === 0 ? (
        <EmptyState
          title={t('tracking.forms.empty.title')}
          description={t('tracking.forms.empty.body')}
        />
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          {forms.data.map((form) => (
            <FormCard
              key={form.id}
              form={form}
              canManage={canManage}
              onEdit={() => setEditing(form)}
            />
          ))}
        </div>
      )}
    </div>
  );
}
