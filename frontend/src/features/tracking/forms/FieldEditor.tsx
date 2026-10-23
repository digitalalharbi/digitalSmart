import { useTranslation } from 'react-i18next';
import { Button } from '@/shared/ui/button';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Textarea } from '@/shared/ui/textarea';
import { FIELD_TYPES, optionsToText, textToOptions } from './fieldOptions';
import type { FieldType, FormField, Visibility } from './types';

const SELECT =
  'border-line bg-surface text-content h-11 w-full rounded-lg border px-3 text-[16px] md:h-10 md:text-sm';

/** One field of a form: what it is, how it reads, what it fills in the lead and when it is shown. */
export function FieldEditor({
  field,
  index,
  total,
  others,
  onChange,
  onMove,
  onRemove,
}: {
  field: FormField;
  index: number;
  total: number;
  others: FormField[];
  onChange: (next: FormField) => void;
  onMove: (direction: -1 | 1) => void;
  onRemove: () => void;
}) {
  const { t } = useTranslation();
  const set = <K extends keyof FormField>(key: K, value: FormField[K]) =>
    onChange({ ...field, [key]: value });
  const mapsTo = field.maps_to ?? '';
  const mapKind = mapsTo.startsWith('custom.') ? 'custom' : mapsTo;
  const rule: Visibility | null = field.visibility;

  return (
    <fieldset className="border-line flex flex-col gap-3 rounded-2xl border p-3 sm:p-4">
      <legend className="text-content px-1 text-sm font-semibold">
        {t('tracking.forms.builder.fieldN', { n: index + 1 })}
      </legend>

      <div className="grid gap-3 sm:grid-cols-3">
        <Field label={t('tracking.forms.builder.type')}>
          {(f) => (
            <select
              {...f}
              className={SELECT}
              value={field.type}
              onChange={(e) => set('type', e.target.value as FieldType)}
            >
              {FIELD_TYPES.map((type) => (
                <option key={type} value={type}>
                  {t(`tracking.forms.types.${type}`)}
                </option>
              ))}
            </select>
          )}
        </Field>
        <Field label={t('tracking.forms.builder.key')} hint={t('tracking.forms.builder.keyHint')}>
          {(f) => (
            <Input
              {...f}
              dir="ltr"
              value={field.key}
              onChange={(e) => set('key', e.target.value)}
            />
          )}
        </Field>
        <label className="flex min-h-11 items-center gap-2 self-end text-sm">
          <input
            type="checkbox"
            className="size-5"
            checked={field.required}
            disabled={field.type === 'hidden'}
            onChange={(e) => set('required', e.target.checked)}
          />
          {t('tracking.forms.builder.required')}
        </label>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('tracking.forms.builder.labelAr')}>
          {(f) => (
            <Input
              {...f}
              dir="rtl"
              value={field.label_ar ?? ''}
              onChange={(e) => set('label_ar', e.target.value || null)}
            />
          )}
        </Field>
        <Field label={t('tracking.forms.builder.labelEn')}>
          {(f) => (
            <Input
              {...f}
              dir="ltr"
              value={field.label_en ?? ''}
              onChange={(e) => set('label_en', e.target.value || null)}
            />
          )}
        </Field>
      </div>

      {field.type === 'select' || field.type === 'radio' ? (
        <Field
          label={t('tracking.forms.builder.options')}
          hint={t('tracking.forms.builder.optionsHint')}
        >
          {(f) => (
            <Textarea
              {...f}
              dir="auto"
              rows={4}
              defaultValue={optionsToText(field.options)}
              onBlur={(e) => set('options', textToOptions(e.target.value))}
            />
          )}
        </Field>
      ) : null}

      <div className="grid gap-3 sm:grid-cols-2">
        <Field
          label={t('tracking.forms.builder.mapsTo')}
          hint={t('tracking.forms.builder.mapsToHint')}
        >
          {(f) => (
            <select
              {...f}
              className={SELECT}
              value={mapKind}
              onChange={(e) =>
                set(
                  'maps_to',
                  e.target.value === ''
                    ? null
                    : e.target.value === 'custom'
                      ? 'custom.'
                      : e.target.value,
                )
              }
            >
              <option value="">{t('tracking.forms.builder.mapsNone')}</option>
              {['name', 'email', 'phone', 'message'].map((target) => (
                <option key={target} value={target}>
                  {t(`tracking.forms.targets.${target}`)}
                </option>
              ))}
              <option value="custom">{t('tracking.forms.targets.custom')}</option>
            </select>
          )}
        </Field>
        {mapKind === 'custom' ? (
          <Field label={t('tracking.forms.builder.customKey')}>
            {(f) => (
              <Input
                {...f}
                dir="ltr"
                value={mapsTo.slice(7)}
                onChange={(e) => set('maps_to', `custom.${e.target.value}`)}
              />
            )}
          </Field>
        ) : null}
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <Field label={t('tracking.forms.builder.showWhen')}>
          {(f) => (
            <select
              {...f}
              className={SELECT}
              value={rule?.field ?? ''}
              onChange={(e) =>
                set(
                  'visibility',
                  e.target.value === ''
                    ? null
                    : {
                        field: e.target.value,
                        operator: rule?.operator ?? 'equals',
                        value: rule?.value ?? '',
                      },
                )
              }
            >
              <option value="">{t('tracking.forms.builder.always')}</option>
              {others.map((o) => (
                <option key={o.key} value={o.key}>
                  {o.label_en ?? o.label_ar ?? o.key}
                </option>
              ))}
            </select>
          )}
        </Field>
        {rule === null ? null : (
          <>
            <Field label={t('tracking.forms.builder.operator')}>
              {(f) => (
                <select
                  {...f}
                  className={SELECT}
                  value={rule.operator}
                  onChange={(e) =>
                    set('visibility', {
                      ...rule,
                      operator: e.target.value as Visibility['operator'],
                    })
                  }
                >
                  {(['equals', 'not_equals', 'in', 'filled'] as const).map((op) => (
                    <option key={op} value={op}>
                      {t(`tracking.forms.operators.${op}`)}
                    </option>
                  ))}
                </select>
              )}
            </Field>
            {rule.operator === 'filled' ? null : (
              <Field label={t('tracking.forms.builder.value')}>
                {(f) => (
                  <Input
                    {...f}
                    value={rule.value ?? ''}
                    onChange={(e) => set('visibility', { ...rule, value: e.target.value })}
                  />
                )}
              </Field>
            )}
          </>
        )}
      </div>

      <div className="flex flex-wrap justify-end gap-2">
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="max-md:h-11"
          disabled={index === 0}
          onClick={() => onMove(-1)}
        >
          {t('tracking.forms.builder.moveUp')}
        </Button>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="max-md:h-11"
          disabled={index === total - 1}
          onClick={() => onMove(1)}
        >
          {t('tracking.forms.builder.moveDown')}
        </Button>
        <Button type="button" variant="danger" size="sm" className="max-md:h-11" onClick={onRemove}>
          {t('tracking.forms.builder.remove')}
        </Button>
      </div>
    </fieldset>
  );
}
