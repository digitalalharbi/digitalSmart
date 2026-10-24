import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';
import { useSession, PERMISSIONS } from '@/features/auth';
import { useProjectsQuery } from '@/features/projects';
import { ApiError } from '@/shared/api/client';
import { cn } from '@/shared/lib/cn';
import { formatMinor } from '@/shared/lib/format';
import { toDecimal, toMinor } from '@/shared/lib/money';
import { providerName } from '@/shared/lib/providers';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { Textarea } from '@/shared/ui/textarea';
import {
  OBJECTIVES,
  planToInput,
  usePlanActionMutation,
  usePlanOptionsQuery,
  usePlanQuery,
  useLaunchActionMutation,
  useSavePlanMutation,
  type PlanInput,
} from './api';
import { ActivationApproval } from './ActivationApproval';
import { LaunchExecutions } from './LaunchExecutions';
import { LaunchPlanView } from './LaunchPlanView';

const STEPS = [
  'project',
  'platforms',
  'objective',
  'budget',
  'audience',
  'placement',
  'tracking',
  'creatives',
  'review',
  'launch',
] as const;
type Step = (typeof STEPS)[number];

const selectClass =
  'border-line bg-surface text-content h-11 w-full rounded-lg border px-3 text-base sm:text-sm focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none';

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function emptyInput(): PlanInput {
  return {
    project_id: '',
    name: '',
    objective: 'leads',
    budget_type: 'daily',
    budget_minor: 50_000,
    currency: 'SAR',
    starts_at: today(),
    ends_at: null,
    external_account_ids: [],
    tracking_link_id: null,
    landing_url: null,
    conversion_event_id: null,
    targeting: {
      countries: ['SA'],
      age_min: 18,
      age_max: 54,
      gender: 'all',
      languages: ['ar'],
      interests: null,
    },
    placement: { mode: 'automatic', placements: [] },
    ad_copy: { headline: null, primary_text: null, call_to_action: null },
    creative_request_ids: [],
  };
}

const list = (value: string, upper: boolean): string[] =>
  value
    .split(',')
    .map((part) => (upper ? part.trim().toUpperCase() : part.trim().toLowerCase()))
    .filter(Boolean);

function failure(error: unknown, fallback: string): string {
  return error instanceof ApiError && error.message !== '' ? error.message : fallback;
}

function Labelled({
  label,
  children,
  hint,
}: {
  label: string;
  children: (id: { id: string; 'aria-describedby': string | undefined }) => ReactNode;
  hint?: string | undefined;
}) {
  return (
    <Field label={label} {...(hint === undefined ? {} : { hint })}>
      {(props) => children({ id: props.id, 'aria-describedby': props['aria-describedby'] })}
    </Field>
  );
}

/**
 * A campaign planned once, in ten short steps, and kept.
 *
 * Every step saves to the same plan, so nothing is lost between steps and a plan can be finished later. It is made only of what
 * the project already has — its bound ad accounts, its tracking links, its client's conversions and Creative Hub requests — and
 * nothing is sent to any platform. The last step shows how each platform will read it, and how each is told not to deliver.
 */
export function CampaignPlanWizard() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const { planId } = useParams();
  const { can } = useSession();
  const existing = usePlanQuery(planId);
  const projects = useProjectsQuery({ per_page: 100 });
  const save = useSavePlanMutation();
  const action = usePlanActionMutation(planId ?? '');
  const launchAction = useLaunchActionMutation(planId ?? '');
  const [understood, setUnderstood] = useState(false);
  const [step, setStep] = useState<Step>('project');
  const [input, setInput] = useState<PlanInput>(emptyInput);
  const [budget, setBudget] = useState('500');
  const [reason, setReason] = useState('');
  const options = usePlanOptionsQuery(input.project_id);
  const plan = existing.data;

  /*
   * The form follows the saved plan whenever a new version of it arrives — adjusted while rendering, as React recommends, rather
   * than in an effect that would render the old values first.
   */
  const [synced, setSynced] = useState<string | null>(null);
  const savedKey = plan === undefined ? null : `${plan.id}:${String(plan.version)}:${plan.status}`;

  if (plan !== undefined && savedKey !== synced) {
    setSynced(savedKey);
    setInput(planToInput(plan));
    setBudget(toDecimal(plan.budget_minor, plan.currency));
  }

  if (planId !== undefined && existing.isPending) {
    return <Skeleton className="h-96 w-full" />;
  }

  if (planId !== undefined && existing.isError) {
    return <Alert tone="danger">{t('campaignPlan.loadFailed')}</Alert>;
  }

  const editable =
    plan === undefined
      ? can(PERMISSIONS.campaignBlueprintsCreate)
      : plan.editable && can(PERMISSIONS.campaignBlueprintsUpdate);
  const set = <K extends keyof PlanInput>(key: K, value: PlanInput[K]) =>
    setInput((current) => ({ ...current, [key]: value }));
  const setTargeting = <K extends keyof PlanInput['targeting']>(
    key: K,
    value: PlanInput['targeting'][K],
  ) => setInput((current) => ({ ...current, targeting: { ...current.targeting, [key]: value } }));
  const setCopy = <K extends keyof PlanInput['ad_copy']>(key: K, value: PlanInput['ad_copy'][K]) =>
    setInput((current) => ({ ...current, ad_copy: { ...current.ad_copy, [key]: value } }));
  const toggle = (key: 'external_account_ids' | 'creative_request_ids', id: string) =>
    setInput((current) => ({
      ...current,
      [key]: current[key].includes(id)
        ? current[key].filter((value) => value !== id)
        : [...current[key], id],
    }));

  const persist = (next?: Step) => {
    const body = { ...input, budget_minor: toMinor(budget, input.currency) };
    /*
     * Saved only when something changed. Saving a plan under review sends it back to draft with a new version — right after an
     * edit, wrong after merely stepping through it to read it.
     */
    const changed =
      plan === undefined || JSON.stringify(body) !== JSON.stringify(planToInput(plan));

    if (!editable || !changed) {
      if (next !== undefined) setStep(next);
      return;
    }

    save.mutate(
      { id: plan?.id, input: body },
      {
        onSuccess: (saved) => {
          if (plan === undefined) {
            void navigate(`/campaigns/plans/${saved.id}`, { replace: true });
          }
          if (next !== undefined) setStep(next);
        },
      },
    );
  };

  const index = STEPS.indexOf(step);
  const canSave = input.project_id !== '' && input.name.trim() !== '';

  return (
    <div className="ds-page">
      <PageHeader
        title={plan?.name ?? t('campaignPlan.new')}
        description={t('campaignPlan.subtitle')}
        actions={
          <Link
            to="/campaigns/plans"
            className="text-content-brand text-sm font-medium underline-offset-4 hover:underline"
          >
            {t('campaignPlan.allPlans')}
          </Link>
        }
      />

      {plan !== undefined ? (
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <Badge
            tone={
              plan.status === 'cancelled'
                ? 'neutral'
                : plan.status === 'ready_for_review'
                  ? 'info'
                  : 'accent'
            }
          >
            {t(`campaignPlan.status.${plan.status}`)}
          </Badge>
          <span className="text-content-tertiary">
            {t('campaignPlan.version', { version: plan.version })}
          </span>
          {plan.status_reason !== null ? (
            <span className="text-content-secondary">{plan.status_reason}</span>
          ) : null}
        </div>
      ) : null}

      <ol className="flex gap-2 overflow-x-auto pb-1" aria-label={t('campaignPlan.steps')}>
        {STEPS.map((key, i) => (
          <li key={key} className="shrink-0">
            <button
              type="button"
              aria-current={key === step ? 'step' : undefined}
              disabled={plan === undefined && i > 0}
              onClick={() => setStep(key)}
              className={cn(
                'min-h-11 rounded-full px-4 py-2 text-sm font-medium disabled:opacity-50',
                key === step
                  ? 'bg-action text-action-contrast'
                  : 'bg-surface text-content-secondary hover:bg-surface-hover',
              )}
            >
              <span className="ds-numeric me-1.5">{i + 1}</span>
              {t(`campaignPlan.step.${key}`)}
            </button>
          </li>
        ))}
      </ol>

      <section
        aria-label={t(`campaignPlan.step.${step}`)}
        className="bg-surface flex flex-col gap-4 rounded-3xl p-5 shadow-xs"
      >
        {step === 'project' ? (
          <div className="grid gap-4 sm:grid-cols-2">
            <Labelled label={t('campaignPlan.project')}>
              {(props) => (
                <select
                  {...props}
                  className={selectClass}
                  value={input.project_id}
                  disabled={plan !== undefined || !editable}
                  onChange={(event) =>
                    setInput({
                      ...input,
                      project_id: event.target.value,
                      external_account_ids: [],
                      tracking_link_id: null,
                      conversion_event_id: null,
                      creative_request_ids: [],
                    })
                  }
                >
                  <option value="">{t('campaignPlan.chooseProject')}</option>
                  {(projects.data?.data ?? []).map((project) => (
                    <option key={project.id} value={project.id}>
                      {project.client?.name
                        ? `${project.client.name} — ${project.name}`
                        : project.name}
                    </option>
                  ))}
                </select>
              )}
            </Labelled>
            <Labelled label={t('campaignPlan.name')}>
              {(props) => (
                <Input
                  {...props}
                  dir="auto"
                  value={input.name}
                  disabled={!editable}
                  onChange={(event) => set('name', event.target.value)}
                />
              )}
            </Labelled>
          </div>
        ) : null}

        {step === 'platforms' ? (
          <fieldset className="flex flex-col gap-2">
            <legend className="text-content mb-2 text-sm font-medium">
              {t('campaignPlan.accounts')}
            </legend>
            {(options.data?.accounts ?? []).length === 0 ? (
              <p className="text-content-secondary text-sm">{t('campaignPlan.noAccounts')}</p>
            ) : null}
            {(options.data?.accounts ?? []).map((account) => (
              <label
                key={account.id}
                className="border-border flex min-h-11 items-center gap-3 rounded-xl border px-3 py-2 text-sm"
              >
                <input
                  type="checkbox"
                  checked={input.external_account_ids.includes(account.id)}
                  disabled={!editable}
                  onChange={() => toggle('external_account_ids', account.id)}
                />
                <span className="text-content font-medium">{providerName(account.provider)}</span>
                <span className="text-content-secondary truncate">{account.name}</span>
                {account.currency !== null ? (
                  <span className="text-content-tertiary ms-auto">{account.currency}</span>
                ) : null}
              </label>
            ))}
          </fieldset>
        ) : null}

        {step === 'objective' ? (
          <div
            role="radiogroup"
            aria-label={t('campaignPlan.objective')}
            className="grid grid-cols-2 gap-2 sm:grid-cols-4"
          >
            {OBJECTIVES.map((objective) => (
              <button
                key={objective}
                type="button"
                role="radio"
                aria-checked={input.objective === objective}
                disabled={!editable}
                onClick={() => set('objective', objective)}
                className={cn(
                  'min-h-11 rounded-2xl p-4 text-start text-sm font-medium',
                  input.objective === objective
                    ? 'bg-action text-action-contrast'
                    : 'bg-surface-sunken text-content',
                )}
              >
                {t(`campaignPlan.objectives.${objective}`)}
              </button>
            ))}
          </div>
        ) : null}

        {step === 'budget' ? (
          <div className="grid gap-4 sm:grid-cols-2">
            <Labelled label={t('campaignPlan.budgetType')}>
              {(props) => (
                <select
                  {...props}
                  className={selectClass}
                  value={input.budget_type}
                  disabled={!editable}
                  onChange={(event) =>
                    set('budget_type', event.target.value === 'lifetime' ? 'lifetime' : 'daily')
                  }
                >
                  <option value="daily">{t('campaignPlan.daily')}</option>
                  <option value="lifetime">{t('campaignPlan.lifetime')}</option>
                </select>
              )}
            </Labelled>
            <Labelled label={t('campaignPlan.amount', { currency: input.currency })}>
              {(props) => (
                <Input
                  {...props}
                  inputMode="decimal"
                  value={budget}
                  disabled={!editable}
                  onChange={(event) => setBudget(event.target.value)}
                />
              )}
            </Labelled>
            <Labelled label={t('campaignPlan.currency')} hint={t('campaignPlan.currencyHint')}>
              {(props) => (
                <Input
                  {...props}
                  value={input.currency}
                  maxLength={3}
                  disabled={!editable}
                  onChange={(event) => set('currency', event.target.value.toUpperCase())}
                />
              )}
            </Labelled>
            <Labelled label={t('campaignPlan.starts')}>
              {(props) => (
                <Input
                  {...props}
                  type="date"
                  value={input.starts_at.slice(0, 10)}
                  disabled={!editable}
                  onChange={(event) => set('starts_at', event.target.value)}
                />
              )}
            </Labelled>
            <Labelled
              label={t('campaignPlan.ends')}
              hint={input.budget_type === 'lifetime' ? t('campaignPlan.endsRequired') : undefined}
            >
              {(props) => (
                <Input
                  {...props}
                  type="date"
                  value={input.ends_at?.slice(0, 10) ?? ''}
                  disabled={!editable}
                  onChange={(event) =>
                    set('ends_at', event.target.value === '' ? null : event.target.value)
                  }
                />
              )}
            </Labelled>
          </div>
        ) : null}

        {step === 'audience' ? (
          <div className="grid gap-4 sm:grid-cols-2">
            <Labelled label={t('campaignPlan.countries')} hint={t('campaignPlan.countriesHint')}>
              {(props) => (
                <Input
                  {...props}
                  value={input.targeting.countries.join(', ')}
                  disabled={!editable}
                  onChange={(event) => setTargeting('countries', list(event.target.value, true))}
                />
              )}
            </Labelled>
            <Labelled label={t('campaignPlan.languages')} hint={t('campaignPlan.languagesHint')}>
              {(props) => (
                <Input
                  {...props}
                  value={input.targeting.languages.join(', ')}
                  disabled={!editable}
                  onChange={(event) => setTargeting('languages', list(event.target.value, false))}
                />
              )}
            </Labelled>
            <Labelled label={t('campaignPlan.ageMin')}>
              {(props) => (
                <Input
                  {...props}
                  type="number"
                  min={13}
                  max={65}
                  value={input.targeting.age_min ?? ''}
                  disabled={!editable}
                  onChange={(event) =>
                    setTargeting(
                      'age_min',
                      event.target.value === '' ? null : Number(event.target.value),
                    )
                  }
                />
              )}
            </Labelled>
            <Labelled label={t('campaignPlan.ageMax')}>
              {(props) => (
                <Input
                  {...props}
                  type="number"
                  min={13}
                  max={65}
                  value={input.targeting.age_max ?? ''}
                  disabled={!editable}
                  onChange={(event) =>
                    setTargeting(
                      'age_max',
                      event.target.value === '' ? null : Number(event.target.value),
                    )
                  }
                />
              )}
            </Labelled>
            <Labelled label={t('campaignPlan.gender')}>
              {(props) => (
                <select
                  {...props}
                  className={selectClass}
                  value={input.targeting.gender}
                  disabled={!editable}
                  onChange={(event) =>
                    setTargeting(
                      'gender',
                      event.target.value === 'female'
                        ? 'female'
                        : event.target.value === 'male'
                          ? 'male'
                          : 'all',
                    )
                  }
                >
                  <option value="all">{t('campaignPlan.genderAll')}</option>
                  <option value="female">{t('campaignPlan.genderFemale')}</option>
                  <option value="male">{t('campaignPlan.genderMale')}</option>
                </select>
              )}
            </Labelled>
            <Labelled label={t('campaignPlan.interests')} hint={t('campaignPlan.interestsHint')}>
              {(props) => (
                <Input
                  {...props}
                  dir="auto"
                  value={input.targeting.interests ?? ''}
                  disabled={!editable}
                  onChange={(event) =>
                    setTargeting('interests', event.target.value === '' ? null : event.target.value)
                  }
                />
              )}
            </Labelled>
          </div>
        ) : null}

        {step === 'placement' ? (
          <div className="flex flex-col gap-4">
            <div
              role="radiogroup"
              aria-label={t('campaignPlan.placement')}
              className="grid gap-2 sm:grid-cols-2"
            >
              {(['automatic', 'manual'] as const).map((mode) => (
                <button
                  key={mode}
                  type="button"
                  role="radio"
                  aria-checked={input.placement.mode === mode}
                  disabled={!editable}
                  onClick={() => set('placement', { ...input.placement, mode })}
                  className={cn(
                    'min-h-11 rounded-2xl p-4 text-start text-sm',
                    input.placement.mode === mode
                      ? 'bg-action text-action-contrast'
                      : 'bg-surface-sunken text-content',
                  )}
                >
                  <span className="block font-medium">{t(`campaignPlan.placement_${mode}`)}</span>
                  <span className="block text-xs opacity-80">
                    {t(`campaignPlan.placement_${mode}Hint`)}
                  </span>
                </button>
              ))}
            </div>
            {input.placement.mode === 'manual' ? (
              <Labelled
                label={t('campaignPlan.placements')}
                hint={t('campaignPlan.placementsHint')}
              >
                {(props) => (
                  <Input
                    {...props}
                    value={input.placement.placements.join(', ')}
                    disabled={!editable}
                    onChange={(event) =>
                      set('placement', {
                        ...input.placement,
                        placements: list(event.target.value, false),
                      })
                    }
                  />
                )}
              </Labelled>
            ) : null}
          </div>
        ) : null}

        {step === 'tracking' ? (
          <div className="grid gap-4 sm:grid-cols-2">
            <Labelled
              label={t('campaignPlan.trackingLink')}
              hint={t('campaignPlan.trackingLinkHint')}
            >
              {(props) => (
                <select
                  {...props}
                  className={selectClass}
                  value={input.tracking_link_id ?? ''}
                  disabled={!editable}
                  onChange={(event) =>
                    set('tracking_link_id', event.target.value === '' ? null : event.target.value)
                  }
                >
                  <option value="">{t('campaignPlan.noTrackingLink')}</option>
                  {(options.data?.tracking_links ?? []).map((link) => (
                    <option key={link.id} value={link.id}>
                      {link.name}
                    </option>
                  ))}
                </select>
              )}
            </Labelled>
            {input.tracking_link_id === null ? (
              <Labelled label={t('campaignPlan.landingUrl')}>
                {(props) => (
                  <Input
                    {...props}
                    type="url"
                    dir="ltr"
                    value={input.landing_url ?? ''}
                    disabled={!editable}
                    onChange={(event) =>
                      set('landing_url', event.target.value === '' ? null : event.target.value)
                    }
                  />
                )}
              </Labelled>
            ) : null}
            <Labelled label={t('campaignPlan.conversion')}>
              {(props) => (
                <select
                  {...props}
                  className={selectClass}
                  value={input.conversion_event_id ?? ''}
                  disabled={!editable}
                  onChange={(event) =>
                    set(
                      'conversion_event_id',
                      event.target.value === '' ? null : event.target.value,
                    )
                  }
                >
                  <option value="">{t('campaignPlan.noConversion')}</option>
                  {(options.data?.conversions ?? []).map((conversion) => (
                    <option key={conversion.id} value={conversion.id}>
                      {conversion.name}
                    </option>
                  ))}
                </select>
              )}
            </Labelled>
            <p className="text-content-tertiary text-xs sm:col-span-2">
              <Link
                to="/tracking"
                className="text-content-brand underline-offset-4 hover:underline"
              >
                {t('campaignPlan.manageTracking')}
              </Link>
            </p>
          </div>
        ) : null}

        {step === 'creatives' ? (
          <div className="flex flex-col gap-4">
            <fieldset className="flex flex-col gap-2">
              <legend className="text-content mb-2 text-sm font-medium">
                {t('campaignPlan.creatives')}
              </legend>
              {(options.data?.creatives ?? []).length === 0 ? (
                <p className="text-content-secondary text-sm">{t('campaignPlan.noCreatives')}</p>
              ) : null}
              {(options.data?.creatives ?? []).map((creative) => (
                <label
                  key={creative.id}
                  className="border-border flex min-h-11 items-center gap-3 rounded-xl border px-3 py-2 text-sm"
                >
                  <input
                    type="checkbox"
                    checked={input.creative_request_ids.includes(creative.id)}
                    disabled={!editable}
                    onChange={() => toggle('creative_request_ids', creative.id)}
                  />
                  <span className="text-content">{creative.title}</span>
                  <span className="text-content-tertiary ms-auto">{creative.state}</span>
                </label>
              ))}
            </fieldset>
            <div className="grid gap-4 sm:grid-cols-2">
              <Labelled label={t('campaignPlan.headline')}>
                {(props) => (
                  <Input
                    {...props}
                    dir="auto"
                    value={input.ad_copy.headline ?? ''}
                    disabled={!editable}
                    onChange={(event) =>
                      setCopy('headline', event.target.value === '' ? null : event.target.value)
                    }
                  />
                )}
              </Labelled>
              <Labelled label={t('campaignPlan.callToAction')}>
                {(props) => (
                  <Input
                    {...props}
                    dir="auto"
                    value={input.ad_copy.call_to_action ?? ''}
                    disabled={!editable}
                    onChange={(event) =>
                      setCopy(
                        'call_to_action',
                        event.target.value === '' ? null : event.target.value,
                      )
                    }
                  />
                )}
              </Labelled>
              <Labelled label={t('campaignPlan.primaryText')}>
                {(props) => (
                  <Textarea
                    {...props}
                    dir="auto"
                    value={input.ad_copy.primary_text ?? ''}
                    disabled={!editable}
                    onChange={(event) =>
                      setCopy('primary_text', event.target.value === '' ? null : event.target.value)
                    }
                  />
                )}
              </Labelled>
            </div>
          </div>
        ) : null}

        {step === 'review' && plan !== undefined ? (
          <div className="flex flex-col gap-4">
            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
              {(
                [
                  ['client', `${plan.client.name} — ${plan.project.name}`],
                  ['objective', t(`campaignPlan.objectives.${plan.objective}`)],
                  [
                    'budget',
                    `${formatMinor(plan.budget_minor, plan.currency, i18n.language)} · ${t(`campaignPlan.${plan.budget_type}`)}`,
                  ],
                  [
                    'dates',
                    `${plan.starts_at.slice(0, 10)} → ${plan.ends_at?.slice(0, 10) ?? t('campaignPlan.noEnd')}`,
                  ],
                  [
                    'accounts',
                    plan.targets
                      .map((target) => `${providerName(target.provider)} · ${target.name}`)
                      .join(', ') || '—',
                  ],
                  ['destination', plan.destination ?? '—'],
                  ['conversion', plan.conversion?.name ?? '—'],
                  ['creativesCount', String(plan.creatives.length)],
                ] as const
              ).map(([key, value]) => (
                <div key={key} className="flex flex-col">
                  <dt className="text-content-tertiary">{t(`campaignPlan.review.${key}`)}</dt>
                  <dd className="text-content break-words">{value}</dd>
                </div>
              ))}
            </dl>
            {(plan.missing ?? []).length > 0 ? (
              <Alert tone="warning">
                {t('campaignPlan.missing', { items: (plan.missing ?? []).join(', ') })}
              </Alert>
            ) : null}
            {action.isError ? (
              <Alert tone="danger">{failure(action.error, t('campaignPlan.actionFailed'))}</Alert>
            ) : null}
            <div className="flex flex-wrap gap-2">
              {plan.status === 'ready_for_review' && can(PERMISSIONS.campaignLaunchApprove) ? (
                <Button
                  onClick={() => launchAction.mutate('approve')}
                  disabled={launchAction.isPending}
                >
                  {t('launch.approve')}
                </Button>
              ) : null}
              {plan.status === 'draft' && can(PERMISSIONS.campaignBlueprintsSubmit) ? (
                <Button
                  onClick={() => action.mutate({ action: 'submit' })}
                  disabled={action.isPending}
                >
                  {t('campaignPlan.submit')}
                </Button>
              ) : null}
              {plan.editable && can(PERMISSIONS.campaignBlueprintsUpdate) ? (
                <>
                  <Input
                    aria-label={t('campaignPlan.reason')}
                    placeholder={t('campaignPlan.reason')}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    className="max-w-xs"
                  />
                  {plan.status === 'ready_for_review' ? (
                    <Button
                      variant="secondary"
                      disabled={reason.trim() === '' || action.isPending}
                      onClick={() => action.mutate({ action: 'return', reason })}
                    >
                      {t('campaignPlan.returnToDraft')}
                    </Button>
                  ) : null}
                  <Button
                    variant="ghost"
                    disabled={reason.trim() === '' || action.isPending}
                    onClick={() => action.mutate({ action: 'cancel', reason })}
                  >
                    {t('campaignPlan.cancel')}
                  </Button>
                </>
              ) : null}
            </div>
            <p className="text-content-tertiary text-xs">{t('campaignPlan.reviewHint')}</p>
          </div>
        ) : null}

        {step === 'launch' && plan !== undefined ? (
          <div className="flex flex-col gap-4">
            <LaunchPlanView planId={plan.id} version={plan.version} />
            {plan.status === 'approved' && can(PERMISSIONS.campaignLaunchExecute) ? (
              <div className="border-border flex flex-col gap-3 rounded-2xl border p-4">
                <label className="flex min-h-11 items-start gap-3 text-sm">
                  <input
                    type="checkbox"
                    checked={understood}
                    onChange={(event) => setUnderstood(event.target.checked)}
                    className="mt-1"
                  />
                  <span className="text-content">{t('launch.confirm')}</span>
                </label>
                <Button
                  className="self-start"
                  disabled={!understood || launchAction.isPending}
                  onClick={() => launchAction.mutate('launch')}
                >
                  {t('launch.create')}
                </Button>
              </div>
            ) : null}
            {plan.status === 'verified' && can(PERMISSIONS.campaignLaunchExecute) ? (
              <Button
                variant="secondary"
                className="self-start"
                disabled={launchAction.isPending}
                onClick={() => launchAction.mutate('activation')}
              >
                {t('activation.request')}
              </Button>
            ) : null}
            {plan.status === 'activation_pending' && can(PERMISSIONS.campaignLaunchActivate) ? (
              <ActivationApproval
                plan={plan}
                pending={launchAction.isPending}
                onApprove={() => launchAction.mutate('activate')}
              />
            ) : null}
            {plan.status === 'activation_pending' && !can(PERMISSIONS.campaignLaunchActivate) ? (
              <Alert tone="info">{t('activation.waiting')}</Alert>
            ) : null}
            <LaunchExecutions
              planId={plan.id}
              canExecute={can(PERMISSIONS.campaignLaunchExecute)}
            />
          </div>
        ) : null}

        {launchAction.isError ? (
          <Alert tone="danger">{failure(launchAction.error, t('campaignPlan.actionFailed'))}</Alert>
        ) : null}
        {save.isError ? (
          <Alert tone="danger">{failure(save.error, t('campaignPlan.saveFailed'))}</Alert>
        ) : null}

        <div className="flex flex-wrap justify-between gap-2">
          <Button
            variant="secondary"
            disabled={index === 0}
            onClick={() => setStep(STEPS[index - 1] ?? 'project')}
          >
            {t('campaignPlan.back')}
          </Button>
          <div className="flex gap-2">
            {editable ? (
              <Button
                variant="secondary"
                disabled={!canSave || save.isPending}
                onClick={() => persist()}
              >
                {t('campaignPlan.saveDraft')}
              </Button>
            ) : null}
            {index < STEPS.length - 1 ? (
              <Button
                disabled={!canSave || save.isPending}
                onClick={() => persist(STEPS[index + 1])}
              >
                {t('campaignPlan.next')}
              </Button>
            ) : null}
          </div>
        </div>
      </section>
    </div>
  );
}
