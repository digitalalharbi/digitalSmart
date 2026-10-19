import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { Check, CircleSlash, Minus } from 'lucide-react';
import { useProjectsQuery } from '@/features/projects';
import { api } from '@/shared/api/client';
import { cn } from '@/shared/lib/cn';
import { providerName } from '@/shared/lib/providers';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Input } from '@/shared/ui/input';
import { PageHeader } from '@/shared/ui/page-header';

type Field = {
  field: string;
  support: 'supported' | 'partial' | 'unsupported';
  note: string | null;
};
type Translation = {
  provider: string;
  objective: string | null;
  daily_budget_minor: number;
  currency: string;
  creates: string;
  fields: Field[];
};
type Drafts = {
  batch_id: string;
  drafts: Array<{ id: string; provider: string; approval_id: string }>;
  skipped: string[];
};

const GOALS = [
  'awareness',
  'reach',
  'traffic',
  'engagement',
  'video_views',
  'leads',
  'app_promotion',
  'sales',
] as const;
const PLATFORMS = ['meta', 'google_ads', 'tiktok', 'snapchat', 'linkedin', 'x', 'openai'] as const;
const STEPS = ['goal', 'budget', 'audience', 'creative', 'platforms', 'review'] as const;
const SUPPORT_ICON = { supported: Check, partial: Minus, unsupported: CircleSlash } as const;
const SUPPORT_TONE = {
  supported: 'bg-success-surface text-success',
  partial: 'bg-warning-surface text-warning',
  unsupported: 'bg-surface-sunken text-content-tertiary',
} as const;

/**
 * One campaign, described once, for several platforms.
 *
 * Six short steps. The review shows every platform's own objective and, field by field, what it takes, takes
 * differently, or cannot take — before anything is made. "Create drafts" makes paused drafts and one launch request
 * each; it calls no platform, and approving a request starts nothing by itself.
 */
export function CampaignBuilderPage() {
  const { t } = useTranslation();
  const projects = useProjectsQuery({ per_page: 100 });
  const [step, setStep] = useState(0);
  const [form, setForm] = useState({
    project_id: '',
    name: '',
    goal: 'leads' as (typeof GOALS)[number],
    daily_budget: '500',
    currency: 'SAR',
    starts_on: '',
    ends_on: '',
    countries: 'SA',
    age_min: '18',
    age_max: '54',
    gender: 'all',
    languages: 'ar,en',
    interests: '',
    headline: '',
    primary_text: '',
    call_to_action: 'Learn more',
    landing_url: '',
    platforms: ['meta', 'tiktok', 'snapchat'] as string[],
  });
  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) =>
    setForm((f) => ({ ...f, [key]: value }));

  const body = () => ({
    name: form.name.trim(),
    goal: form.goal,
    daily_budget_minor: Math.round(Number(form.daily_budget) * 100),
    currency: form.currency.trim().toUpperCase(),
    starts_on: form.starts_on,
    ends_on: form.ends_on === '' ? null : form.ends_on,
    countries: form.countries
      .split(',')
      .map((c) => c.trim().toUpperCase())
      .filter(Boolean),
    age_min: form.age_min === '' ? null : Number(form.age_min),
    age_max: form.age_max === '' ? null : Number(form.age_max),
    gender: form.gender,
    languages: form.languages
      .split(',')
      .map((c) => c.trim().toLowerCase())
      .filter(Boolean),
    interests: form.interests.trim() === '' ? null : form.interests.trim(),
    headline: form.headline.trim(),
    primary_text: form.primary_text.trim(),
    call_to_action: form.call_to_action.trim(),
    landing_url: form.landing_url.trim() === '' ? null : form.landing_url.trim(),
    platforms: form.platforms,
  });

  const preview = useMutation({
    mutationFn: async (): Promise<Translation[]> =>
      (await api.post<{ data: Translation[] }>('/campaign-blueprints/preview', body())).data.data,
  });
  const create = useMutation({
    mutationFn: async (): Promise<Drafts> =>
      (
        await api.post<{ data: Drafts }>('/campaign-blueprints/drafts', {
          ...body(),
          project_id: form.project_id,
        })
      ).data.data,
  });

  const go = (next: number) => {
    setStep(next);

    if (STEPS[next] === 'review') {
      preview.mutate();
    }
  };

  const ready =
    form.name.trim() !== '' &&
    form.headline.trim() !== '' &&
    form.primary_text.trim() !== '' &&
    form.platforms.length > 0 &&
    form.starts_on !== '' &&
    Number(form.daily_budget) > 0;

  return (
    <div className="ds-page">
      <PageHeader title={t('builder.title')} description={t('builder.subtitle')} />

      <ol className="flex flex-wrap gap-2" aria-label={t('builder.steps')}>
        {STEPS.map((key, index) => (
          <li key={key}>
            <button
              type="button"
              aria-current={index === step ? 'step' : undefined}
              onClick={() => go(index)}
              className={cn(
                'rounded-full px-4 py-2 text-sm font-medium',
                index === step
                  ? 'bg-action text-action-contrast'
                  : 'bg-surface text-content-secondary hover:bg-surface-hover',
              )}
            >
              <span className="ds-numeric me-1.5">{index + 1}</span>
              {t(`builder.step.${key}`)}
            </button>
          </li>
        ))}
      </ol>

      <section className="bg-surface flex flex-col gap-4 rounded-3xl p-5 shadow-xs">
        {STEPS[step] === 'goal' ? (
          <>
            <Labelled label={t('builder.name')}>
              <Input value={form.name} onChange={(e) => set('name', e.target.value)} dir="auto" />
            </Labelled>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              {GOALS.map((goal) => (
                <button
                  key={goal}
                  type="button"
                  aria-pressed={form.goal === goal}
                  onClick={() => set('goal', goal)}
                  className={cn(
                    'rounded-2xl p-4 text-start text-sm font-medium',
                    form.goal === goal
                      ? 'bg-action text-action-contrast'
                      : 'bg-surface-sunken text-content',
                  )}
                >
                  {t(`builder.goal.${goal}`)}
                </button>
              ))}
            </div>
          </>
        ) : null}

        {STEPS[step] === 'budget' ? (
          <div className="grid gap-3 sm:grid-cols-2">
            <Labelled label={t('builder.daily')}>
              <Input
                inputMode="decimal"
                value={form.daily_budget}
                onChange={(e) => set('daily_budget', e.target.value)}
              />
            </Labelled>
            <Labelled label={t('builder.currency')}>
              <Input
                value={form.currency}
                maxLength={3}
                onChange={(e) => set('currency', e.target.value)}
              />
            </Labelled>
            <Labelled label={t('builder.starts')}>
              <Input
                type="date"
                value={form.starts_on}
                onChange={(e) => set('starts_on', e.target.value)}
              />
            </Labelled>
            <Labelled label={t('builder.ends')}>
              <Input
                type="date"
                value={form.ends_on}
                onChange={(e) => set('ends_on', e.target.value)}
              />
            </Labelled>
            <p className="text-content-tertiary text-xs sm:col-span-2">{t('builder.split')}</p>
          </div>
        ) : null}

        {STEPS[step] === 'audience' ? (
          <div className="grid gap-3 sm:grid-cols-2">
            <Labelled label={t('builder.countries')}>
              <Input value={form.countries} onChange={(e) => set('countries', e.target.value)} />
            </Labelled>
            <Labelled label={t('builder.languages')}>
              <Input value={form.languages} onChange={(e) => set('languages', e.target.value)} />
            </Labelled>
            <Labelled label={t('builder.ageMin')}>
              <Input
                inputMode="numeric"
                value={form.age_min}
                onChange={(e) => set('age_min', e.target.value)}
              />
            </Labelled>
            <Labelled label={t('builder.ageMax')}>
              <Input
                inputMode="numeric"
                value={form.age_max}
                onChange={(e) => set('age_max', e.target.value)}
              />
            </Labelled>
            <Labelled label={t('builder.interests')}>
              <Input
                value={form.interests}
                onChange={(e) => set('interests', e.target.value)}
                dir="auto"
              />
            </Labelled>
          </div>
        ) : null}

        {STEPS[step] === 'creative' ? (
          <div className="grid gap-3">
            <Labelled label={t('builder.headline')}>
              <Input
                value={form.headline}
                onChange={(e) => set('headline', e.target.value)}
                dir="auto"
              />
            </Labelled>
            <Labelled label={t('builder.text')}>
              <Input
                value={form.primary_text}
                onChange={(e) => set('primary_text', e.target.value)}
                dir="auto"
              />
            </Labelled>
            <div className="grid gap-3 sm:grid-cols-2">
              <Labelled label={t('builder.cta')}>
                <Input
                  value={form.call_to_action}
                  onChange={(e) => set('call_to_action', e.target.value)}
                  dir="auto"
                />
              </Labelled>
              <Labelled label={t('builder.url')}>
                <Input
                  type="url"
                  value={form.landing_url}
                  onChange={(e) => set('landing_url', e.target.value)}
                />
              </Labelled>
            </div>
          </div>
        ) : null}

        {STEPS[step] === 'platforms' ? (
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {PLATFORMS.map((platform) => {
              const on = form.platforms.includes(platform);

              return (
                <button
                  key={platform}
                  type="button"
                  aria-pressed={on}
                  onClick={() =>
                    set(
                      'platforms',
                      on
                        ? form.platforms.filter((p) => p !== platform)
                        : [...form.platforms, platform],
                    )
                  }
                  className={cn(
                    'rounded-2xl p-4 text-start text-sm font-medium',
                    on ? 'bg-action text-action-contrast' : 'bg-surface-sunken text-content',
                  )}
                >
                  {providerName(platform)}
                </button>
              );
            })}
          </div>
        ) : null}

        {STEPS[step] === 'review' ? (
          <div className="flex flex-col gap-4">
            {preview.isError ? <Alert tone="danger">{t('builder.invalid')}</Alert> : null}
            {preview.data === undefined ? null : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[36rem] text-sm">
                  <thead>
                    <tr>
                      <th className="text-content-tertiary py-2 text-start text-xs font-semibold">
                        {t('builder.field')}
                      </th>
                      {preview.data.map((p) => (
                        <th
                          key={p.provider}
                          className="text-content py-2 text-start text-xs font-semibold"
                        >
                          {providerName(p.provider)}
                          <span className="text-content-tertiary block font-mono font-normal">
                            {p.objective ?? '—'}
                          </span>
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-line-subtle divide-y">
                    {(preview.data[0]?.fields ?? []).map((row) =>
                      row.field === 'platform' ? null : (
                        <tr key={row.field}>
                          <td className="text-content-secondary py-2">
                            {t(`builder.fields.${row.field}`, { defaultValue: row.field })}
                          </td>
                          {preview.data.map((p) => {
                            const cell = p.fields.find((f) => f.field === row.field) ?? p.fields[0];

                            if (cell === undefined) return <td key={p.provider} />;

                            const Icon = SUPPORT_ICON[cell.support];

                            return (
                              <td key={p.provider} className="py-2">
                                <span
                                  title={cell.note ?? undefined}
                                  className={cn(
                                    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold',
                                    SUPPORT_TONE[cell.support],
                                  )}
                                >
                                  <Icon aria-hidden className="size-3" />
                                  {t(`builder.support.${cell.support}`)}
                                </span>
                              </td>
                            );
                          })}
                        </tr>
                      ),
                    )}
                  </tbody>
                </table>
                <ul className="text-content-tertiary mt-3 flex flex-col gap-1 text-xs">
                  {preview.data.flatMap((p) =>
                    p.fields
                      .filter((f) => f.note !== null)
                      .map((f) => (
                        <li key={`${p.provider}-${f.field}`}>
                          <span className="text-content-secondary font-medium">
                            {providerName(p.provider)}
                          </span>{' '}
                          · {f.note}
                        </li>
                      )),
                  )}
                </ul>
              </div>
            )}

            <Labelled label={t('builder.project')}>
              <select
                className="bg-surface-sunken text-content h-10 rounded-full px-3 text-sm"
                value={form.project_id}
                onChange={(e) => set('project_id', e.target.value)}
              >
                <option value="">{t('builder.chooseProject')}</option>
                {(projects.data?.data ?? []).map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.client.name} · {p.name}
                  </option>
                ))}
              </select>
            </Labelled>

            <p className="text-content-secondary text-sm">{t('builder.honest')}</p>

            {create.isError ? <Alert tone="danger">{t('builder.failed')}</Alert> : null}
            {create.data === undefined ? (
              <Button
                className="self-start"
                disabled={!ready || form.project_id === '' || create.isPending}
                onClick={() => create.mutate()}
              >
                {t('builder.create')}
              </Button>
            ) : (
              <Alert tone="info">
                {t('builder.created', { count: create.data.drafts.length })}{' '}
                {create.data.skipped.length > 0
                  ? t('builder.skipped', {
                      platforms: create.data.skipped.map(providerName).join(', '),
                    })
                  : null}{' '}
                <Link to="/approvals" className="underline">
                  {t('builder.toApprovals')}
                </Link>
              </Alert>
            )}
          </div>
        ) : null}
      </section>

      <div className="flex justify-between">
        <Button variant="secondary" disabled={step === 0} onClick={() => go(step - 1)}>
          {t('builder.back')}
        </Button>
        {step < STEPS.length - 1 ? (
          <Button onClick={() => go(step + 1)}>{t('builder.next')}</Button>
        ) : null}
      </div>
    </div>
  );
}

function Labelled({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-content-secondary text-xs font-semibold">{label}</span>
      {children}
    </label>
  );
}
