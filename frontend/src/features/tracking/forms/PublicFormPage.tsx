import { useEffect, useMemo, useRef, useState } from 'react';
import { useParams, useSearchParams } from 'react-router';
import { useMutation, useQuery } from '@tanstack/react-query';
import { api, ApiError } from '@/shared/api/client';
import { Input } from '@/shared/ui/input';
import { Textarea } from '@/shared/ui/textarea';
import { beacon, eventId } from './beacon';
import { isShown } from './visibility';
import type { FormField, PublicForm, SubmitResult } from './types';

type Locale = 'ar' | 'en';

/*
 * The visitor-facing strings live here and not in the application's translation files: this page is public, carries its own
 * language (the form's, not the application's), and must read correctly for a person who has never signed in.
 */
const COPY: Record<Locale, Record<string, string>> = {
  ar: {
    submit: 'إرسال',
    sending: 'جارٍ الإرسال…',
    required: 'هذا الحقل مطلوب',
    invalid: 'القيمة غير صحيحة',
    too_long: 'النص أطول من المسموح',
    too_short: 'النص أقصر من المطلوب',
    too_small: 'القيمة أقل من المسموح',
    too_large: 'القيمة أكبر من المسموح',
    failed: 'تعذّر إرسال النموذج. حاول مرة أخرى.',
    choose: 'اختر…',
    notFound: 'هذا النموذج غير متاح.',
    loading: 'جارٍ التحميل…',
    thanks: 'تم استلام طلبك.',
    language: 'English',
    check: 'يرجى مراجعة الحقول المحددة.',
  },
  en: {
    submit: 'Submit',
    sending: 'Sending…',
    required: 'This field is required',
    invalid: 'This value is not valid',
    too_long: 'This is longer than allowed',
    too_short: 'This is shorter than required',
    too_small: 'This is below the allowed value',
    too_large: 'This is above the allowed value',
    failed: 'The form could not be sent. Please try again.',
    choose: 'Choose…',
    notFound: 'This form is not available.',
    loading: 'Loading…',
    thanks: 'Your request has been received.',
    language: 'العربية',
    check: 'Please check the highlighted fields.',
  },
};

function label(field: FormField, locale: Locale): string {
  return (
    (locale === 'en' ? field.label_en : field.label_ar) ??
    field.label_ar ??
    field.label_en ??
    field.key
  );
}

/** Black or white, whichever reads better on the accent the agency chose. */
function inkOn(hex: string): string {
  const n = Number.parseInt(hex.slice(1), 16);
  const lin = (c: number) => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  };
  const l = 0.2126 * lin((n >> 16) & 255) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255);

  return l > 0.4 ? '#111111' : '#ffffff';
}

function Control({
  field,
  locale,
  value,
  invalid,
  describedBy,
  onChange,
}: {
  field: FormField;
  locale: Locale;
  value: string;
  invalid: boolean;
  describedBy: string | undefined;
  onChange: (value: string) => void;
}) {
  const id = `f-${field.key}`;
  const placeholder = (locale === 'en' ? field.placeholder_en : field.placeholder_ar) ?? undefined;
  const common = { id, 'aria-describedby': describedBy, invalid } as const;
  const optionLabel = (o: FormField['options'][number]) =>
    (locale === 'en' ? o.label_en : o.label_ar) ?? o.label_ar ?? o.label_en ?? o.value;

  switch (field.type) {
    case 'textarea':
      return (
        <Textarea
          {...common}
          value={value}
          placeholder={placeholder}
          onChange={(e) => onChange(e.target.value)}
        />
      );
    case 'select':
      return (
        <select
          id={id}
          aria-describedby={describedBy}
          aria-invalid={invalid || undefined}
          className="border-line bg-surface text-content h-11 w-full rounded-md border px-3 text-[16px]"
          value={value}
          onChange={(e) => onChange(e.target.value)}
        >
          <option value="">{COPY[locale].choose}</option>
          {field.options.map((o) => (
            <option key={o.value} value={o.value}>
              {optionLabel(o)}
            </option>
          ))}
        </select>
      );
    case 'radio':
      return (
        <div role="radiogroup" aria-labelledby={`${id}-label`} className="flex flex-col gap-2">
          {field.options.map((o) => (
            <label
              key={o.value}
              className="border-line flex min-h-11 items-center gap-3 rounded-md border px-3 text-[16px]"
            >
              <input
                type="radio"
                name={field.key}
                className="size-5"
                checked={value === o.value}
                onChange={() => onChange(o.value)}
              />
              {optionLabel(o)}
            </label>
          ))}
        </div>
      );
    case 'checkbox':
    case 'consent':
      return (
        <label className="flex min-h-11 items-start gap-3 text-[16px]">
          <input
            id={id}
            type="checkbox"
            className="mt-1 size-5"
            aria-describedby={describedBy}
            aria-invalid={invalid || undefined}
            checked={value === '1'}
            onChange={(e) => onChange(e.target.checked ? '1' : '')}
          />
          <span>{label(field, locale)}</span>
        </label>
      );
    default: {
      const types: Record<string, string> = {
        email: 'email',
        phone: 'tel',
        number: 'number',
        date: 'date',
      };
      const type = types[field.type] ?? 'text';

      return (
        <Input
          {...common}
          type={type}
          inputMode={
            field.type === 'phone' ? 'tel' : field.type === 'number' ? 'decimal' : undefined
          }
          autoComplete={
            field.type === 'phone' ? 'tel' : field.type === 'email' ? 'email' : undefined
          }
          dir={
            field.type === 'email' || field.type === 'phone' || field.type === 'number'
              ? 'ltr'
              : undefined
          }
          value={value}
          placeholder={placeholder}
          onChange={(e) => onChange(e.target.value)}
        />
      );
    }
  }
}

/** The public form: what a visitor sees at `/f/{token}`. Mobile first, one column, no sign-in, nothing of the tenant. */
export function PublicFormPage() {
  const { token = '' } = useParams();
  const [params] = useSearchParams();
  const touchpoint = params.get('ds_tp');
  const form = useQuery({
    queryKey: ['public-form', token],
    retry: false,
    queryFn: async (): Promise<PublicForm> =>
      (await api.get<{ data: PublicForm }>(`/public/forms/${token}`)).data.data,
  });
  const requested = params.get('lang');
  const [locale, setLocale] = useState<Locale | null>(
    requested === 'en' || requested === 'ar' ? requested : null,
  );
  const lang: Locale = locale ?? form.data?.default_locale ?? 'ar';
  const [values, setValues] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [done, setDone] = useState<SubmitResult | null>(null);
  const [failed, setFailed] = useState(false);
  const started = useRef(false);
  const submissionId = useRef(eventId());
  const viewed = useRef(false);
  const honeypot = useRef<HTMLInputElement>(null);

  const t = COPY[lang];
  const fields = useMemo(() => form.data?.fields ?? [], [form.data]);

  useEffect(() => {
    if (form.data && !viewed.current) {
      viewed.current = true;
      beacon({ touchpoint, event: 'form_view', event_id: eventId(), meta: { form_token: token } });
    }
  }, [form.data, touchpoint, token]);

  /* Defaults are what a field shows until the visitor types; computed, not copied into state by an effect. */
  const answers = useMemo(() => {
    const defaults: Record<string, string> = {};
    fields.forEach((f) => {
      if (f.default !== null) {
        defaults[f.key] = f.default;
      }
    });

    return { ...defaults, ...values };
  }, [fields, values]);

  const submit = useMutation({
    mutationFn: async (): Promise<SubmitResult> => {
      const visible: Record<string, string> = {};
      fields.forEach((f) => {
        if (isShown(f.visibility, answers) && answers[f.key] !== undefined) {
          visible[f.key] = answers[f.key] ?? '';
        }
      });
      const { data } = await api.post<{ data: SubmitResult }>(`/public/forms/${token}/submit`, {
        event_id: submissionId.current,
        touchpoint,
        locale: lang,
        values: visible,
        website: honeypot.current?.value ?? '',
      });

      return data.data;
    },
    onSuccess: (result) => {
      setDone(result);
      setErrors({});

      if (result.redirect_url) {
        window.setTimeout(() => window.location.assign(result.redirect_url ?? '/'), 1500);
      }
    },
    onError: (error) => {
      const fieldErrors = error instanceof ApiError ? readErrors(error) : {};
      setErrors(fieldErrors);
      setFailed(Object.keys(fieldErrors).length === 0);

      Object.keys(fieldErrors).forEach((key) =>
        beacon({
          touchpoint,
          event: 'form_validation_error',
          event_id: eventId(),
          meta: { field_key: key, error_code: fieldErrors[key] },
        }),
      );
    },
  });

  const change = (key: string, value: string) => {
    if (!started.current) {
      started.current = true;
      beacon({ touchpoint, event: 'form_start', event_id: eventId(), meta: { field_key: key } });
    }
    setValues((v) => ({ ...v, [key]: value }));
    setErrors((e) => {
      return Object.fromEntries(Object.entries(e).filter(([name]) => name !== key));
    });
  };

  const dir = lang === 'ar' ? 'rtl' : 'ltr';
  const accent = form.data?.accent ?? null;
  const theme =
    form.data?.theme === 'auto' || form.data === undefined ? undefined : form.data.theme;

  return (
    <div
      dir={dir}
      lang={lang}
      data-theme={theme}
      className="bg-workspace text-content ds-safe-area min-h-dvh px-4 py-6 sm:py-10"
    >
      <main className="mx-auto flex w-full max-w-lg flex-col gap-5">
        {form.isPending ? (
          <p role="status">{t['loading']}</p>
        ) : form.isError ? (
          <h1 className="text-lg font-semibold">{t['notFound']}</h1>
        ) : done ? (
          <section
            className="bg-surface border-line flex flex-col gap-3 rounded-3xl border p-6"
            role="status"
          >
            <h1 className="text-xl font-semibold">
              {(lang === 'en' ? done.thank_you.en : done.thank_you.ar) ?? t['thanks']}
            </h1>
          </section>
        ) : (
          <form
            noValidate
            className="bg-surface border-line flex flex-col gap-5 rounded-3xl border p-5 sm:p-7"
            onSubmit={(event) => {
              event.preventDefault();
              setFailed(false);
              submit.mutate();
            }}
          >
            <header className="flex items-start justify-between gap-3">
              <div className="flex min-w-0 flex-col gap-3">
                {form.data.logo_url ? (
                  <img
                    src={form.data.logo_url}
                    alt=""
                    className="object-start max-h-12 w-auto max-w-40 object-contain"
                  />
                ) : null}
                <h1 className="text-xl font-semibold text-balance">{form.data.name}</h1>
              </div>
              <button
                type="button"
                className="text-content-secondary min-h-11 shrink-0 px-2 text-sm underline"
                onClick={() => setLocale(lang === 'ar' ? 'en' : 'ar')}
              >
                {t['language']}
              </button>
            </header>

            {Object.keys(errors).length > 0 ? (
              <p role="alert" className="text-danger text-sm">
                {t['check']}
              </p>
            ) : null}

            {fields.map((field) => {
              if (!isShown(field.visibility, answers)) {
                return null;
              }

              if (field.type === 'hidden') {
                return null;
              }

              const error = errors[field.key];
              const help = (lang === 'en' ? field.help_en : field.help_ar) ?? undefined;
              const errorId = `f-${field.key}-error`;
              const bare = field.type === 'checkbox' || field.type === 'consent';

              return (
                <div key={field.key} className="flex flex-col gap-1.5">
                  {bare ? null : (
                    <label
                      id={`f-${field.key}-label`}
                      htmlFor={`f-${field.key}`}
                      className="text-sm font-medium"
                    >
                      {label(field, lang)}
                      {field.required ? (
                        <span className="text-danger ms-1" aria-hidden="true">
                          *
                        </span>
                      ) : null}
                    </label>
                  )}
                  <Control
                    field={field}
                    locale={lang}
                    value={answers[field.key] ?? ''}
                    invalid={error !== undefined}
                    describedBy={error ? errorId : undefined}
                    onChange={(v) => change(field.key, v)}
                  />
                  {help && !error ? <p className="text-content-tertiary text-xs">{help}</p> : null}
                  {error ? (
                    <p id={errorId} role="alert" className="text-danger text-sm">
                      {t[error] ?? t['invalid']}
                    </p>
                  ) : null}
                </div>
              );
            })}

            {/* A field no person sees. Anything in it is a script filling every box it finds. */}
            <input
              ref={honeypot}
              type="text"
              name="website"
              tabIndex={-1}
              autoComplete="off"
              aria-hidden="true"
              className="absolute -start-[9999px] h-0 w-0 opacity-0"
            />

            {failed ? (
              <p role="alert" className="text-danger text-sm">
                {t['failed']}
              </p>
            ) : null}

            <button
              type="submit"
              disabled={submit.isPending}
              style={accent ? { backgroundColor: accent, color: inkOn(accent) } : undefined}
              className="bg-action text-action-contrast h-12 w-full rounded-full px-6 text-[16px] font-semibold disabled:opacity-60"
            >
              {submit.isPending
                ? t['sending']
                : ((lang === 'en' ? form.data.submit_label.en : form.data.submit_label.ar) ??
                  t['submit'])}
            </button>
          </form>
        )}
      </main>
    </div>
  );
}

function readErrors(error: ApiError): Record<string, string> {
  const raw = error.payload?.['errors'];

  if (typeof raw === 'object' && raw !== null) {
    return Object.fromEntries(
      Object.entries(raw).filter(
        (entry): entry is [string, string] => typeof entry[1] === 'string',
      ),
    );
  }

  return {};
}
