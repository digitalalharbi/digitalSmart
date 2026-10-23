import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useProjectsQuery } from '@/features/projects';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Card } from '@/shared/ui/card';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { useSaveFormMutation } from './api';
import { FieldEditor } from './FieldEditor';
import type { FormDraft, FormField, FormSummary } from './types';

const SELECT =
  'border-line bg-surface text-content h-11 w-full rounded-lg border px-3 text-[16px] md:h-10 md:text-sm';

let uidCounter = 0;

/** A key for React that survives a field being renamed or moved. */
function nextUid(): number {
  uidCounter += 1;

  return uidCounter;
}

function blank(n: number): FormField {
  return {
    key: `field_${String(n)}`,
    type: 'text',
    label_ar: null,
    label_en: null,
    placeholder_ar: null,
    placeholder_en: null,
    help_ar: null,
    help_en: null,
    required: false,
    default: null,
    options: [],
    validation: {},
    visibility: null,
    maps_to: null,
  };
}

/**
 * Build or change a form.
 *
 * Stacked rather than side by side so it works at 375px: the settings, then the fields in the order the visitor sees them,
 * then save. Reordering is a pair of buttons rather than dragging, which a keyboard and a thumb can both use.
 */
export function FormBuilder({ form, onClose }: { form: FormSummary | null; onClose: () => void }) {
  const { t } = useTranslation();
  const save = useSaveFormMutation();
  const [term, setTerm] = useState('');
  const [project, setProject] = useState<{ id: string; label: string } | null>(
    form === null ? null : { id: form.project.id, label: form.project.name },
  );
  const [draft, setDraft] = useState<Omit<FormDraft, 'project_id' | 'fields'>>({
    name: form?.name ?? '',
    default_locale: form?.default_locale ?? 'ar',
    creates_lead: form?.creates_lead ?? true,
    theme: form?.theme ?? 'auto',
    thank_you_ar: form?.thank_you_ar ?? '',
    thank_you_en: form?.thank_you_en ?? '',
    redirect_url: form?.redirect_url ?? '',
    accent: form?.accent ?? '',
  });
  const [fields, setFields] = useState<Array<{ uid: number; field: FormField }>>(() =>
    (form?.fields ?? []).map((field) => ({ uid: nextUid(), field })),
  );
  const projects = useProjectsQuery({ search: term, per_page: 8 });

  const submit = () => {
    if (project === null) {
      return;
    }

    const clean = (value: string | undefined) =>
      value === undefined || value === '' ? undefined : value;

    save.mutate(
      {
        ...(form === null ? {} : { id: form.id }),
        draft: {
          ...draft,
          thank_you_ar: clean(draft.thank_you_ar) as string,
          thank_you_en: clean(draft.thank_you_en) as string,
          redirect_url: clean(draft.redirect_url) as string,
          accent: clean(draft.accent) as string,
          project_id: project.id,
          fields: fields.map(({ field }) => field),
        },
      },
      { onSuccess: onClose },
    );
  };

  return (
    <Card className="flex flex-col gap-5 p-4 sm:p-6">
      <h2 className="text-content text-lg font-semibold">
        {form === null
          ? t('tracking.forms.builder.newTitle')
          : t('tracking.forms.builder.editTitle', { name: form.name })}
      </h2>

      <form
        className="flex flex-col gap-5"
        onSubmit={(event) => {
          event.preventDefault();
          submit();
        }}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          {form === null ? (
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
          ) : null}
          <Field label={t('tracking.forms.builder.name')} required>
            {(f) => (
              <Input
                {...f}
                value={draft.name}
                onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                required
              />
            )}
          </Field>
          <Field label={t('tracking.forms.builder.locale')}>
            {(f) => (
              <select
                {...f}
                className={SELECT}
                value={draft.default_locale}
                onChange={(e) =>
                  setDraft({ ...draft, default_locale: e.target.value as 'ar' | 'en' })
                }
              >
                <option value="ar">العربية</option>
                <option value="en">English</option>
              </select>
            )}
          </Field>
          <Field label={t('tracking.forms.builder.theme')}>
            {(f) => (
              <select
                {...f}
                className={SELECT}
                value={draft.theme}
                onChange={(e) =>
                  setDraft({ ...draft, theme: e.target.value as 'auto' | 'light' | 'dark' })
                }
              >
                {(['auto', 'light', 'dark'] as const).map((theme) => (
                  <option key={theme} value={theme}>
                    {t(`tracking.forms.themes.${theme}`)}
                  </option>
                ))}
              </select>
            )}
          </Field>
          <Field label={t('tracking.forms.builder.thankYouAr')}>
            {(f) => (
              <Input
                {...f}
                dir="rtl"
                value={draft.thank_you_ar ?? ''}
                onChange={(e) => setDraft({ ...draft, thank_you_ar: e.target.value })}
              />
            )}
          </Field>
          <Field label={t('tracking.forms.builder.thankYouEn')}>
            {(f) => (
              <Input
                {...f}
                dir="ltr"
                value={draft.thank_you_en ?? ''}
                onChange={(e) => setDraft({ ...draft, thank_you_en: e.target.value })}
              />
            )}
          </Field>
          <Field
            label={t('tracking.forms.builder.redirect')}
            hint={t('tracking.forms.builder.redirectHint')}
          >
            {(f) => (
              <Input
                {...f}
                dir="ltr"
                type="url"
                inputMode="url"
                value={draft.redirect_url ?? ''}
                onChange={(e) => setDraft({ ...draft, redirect_url: e.target.value })}
              />
            )}
          </Field>
          <Field
            label={t('tracking.forms.builder.accent')}
            hint={t('tracking.forms.builder.accentHint')}
          >
            {(f) => (
              <Input
                {...f}
                dir="ltr"
                placeholder="#C24A2C"
                maxLength={7}
                value={draft.accent ?? ''}
                onChange={(e) => setDraft({ ...draft, accent: e.target.value })}
              />
            )}
          </Field>
        </div>

        <label className="flex min-h-11 items-start gap-3 text-sm">
          <input
            type="checkbox"
            className="mt-0.5 size-5"
            checked={draft.creates_lead}
            onChange={(e) => setDraft({ ...draft, creates_lead: e.target.checked })}
          />
          <span>
            {t('tracking.forms.builder.createsLead')}
            <span className="text-content-tertiary block text-xs">
              {t('tracking.forms.builder.createsLeadHint')}
            </span>
          </span>
        </label>

        <div className="flex flex-col gap-3">
          {fields.map(({ uid, field }, index) => (
            <FieldEditor
              key={uid}
              field={field}
              index={index}
              total={fields.length}
              others={fields.filter((x) => x.uid !== uid).map((x) => x.field)}
              onChange={(next) =>
                setFields((all) => all.map((x) => (x.uid === uid ? { uid, field: next } : x)))
              }
              onMove={(dir) =>
                setFields((all) => {
                  const copy = [...all];
                  const target = copy[index + dir];
                  const current = copy[index];

                  if (target !== undefined && current !== undefined) {
                    copy[index + dir] = current;
                    copy[index] = target;
                  }

                  return copy;
                })
              }
              onRemove={() => setFields((all) => all.filter((x) => x.uid !== uid))}
            />
          ))}
          <Button
            type="button"
            variant="secondary"
            className="self-start max-md:h-11"
            onClick={() =>
              setFields((all) => [...all, { uid: nextUid(), field: blank(all.length + 1) }])
            }
          >
            {t('tracking.forms.builder.addField')}
          </Button>
        </div>

        {save.isError ? (
          <Alert tone="danger">
            {save.error instanceof ApiError && save.error.message !== ''
              ? save.error.message
              : t('tracking.forms.builder.failed')}
          </Alert>
        ) : null}

        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('actions.cancel')}
          </Button>
          <Button type="submit" disabled={save.isPending || project === null}>
            {t('tracking.forms.builder.save')}
          </Button>
        </div>
      </form>
    </Card>
  );
}
