import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CircleSlash, Plus, Trash2, X } from 'lucide-react';
import { useClientsQuery } from '@/features/clients';
import { useProjectsQuery } from '@/features/projects';
import { api } from '@/shared/api/client';
import { cn } from '@/shared/lib/cn';
import { providerName } from '@/shared/lib/providers';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { ContextDrawer } from '@/shared/ui/context-drawer';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { Input } from '@/shared/ui/input';
import { useCapabilities, useCreateRule, useSimulate, useUpdateRule } from './api';
import { actionText, conditionParts } from './ruleText';
import {
  DEFAULT_OPERATOR,
  NO_THRESHOLD,
  PCT_ACTIONS,
  type CapabilityState,
  type Operator,
  type RuleAction,
  type RuleCondition,
  type RuleDraft,
  type RuleMetric,
  type RuleMode,
  type RuleSchedule,
} from './types';

const METRICS: RuleMetric[] = [
  'spend',
  'roas',
  'cost_per_result',
  'conversions',
  'ctr',
  'cpc',
  'cpm',
  'campaign_age',
  'budget_used',
  'cost_change',
  'no_results',
  'not_delivering',
  'rejected',
  'ends_within',
];
const ACTIONS: RuleAction[] = [
  'alert',
  'propose_pause',
  'propose_resume',
  'propose_budget_increase',
  'propose_budget_cut',
  'propose_bid_change',
  'task',
];
const PLATFORMS = ['meta', 'google_ads', 'tiktok', 'snapchat', 'linkedin', 'x'] as const;
const SELECT = 'bg-surface-sunken text-content h-10 rounded-full px-3 text-sm';

type Picked = { id: string; label: string };
type Row = { metric: RuleMetric; operator: Operator; threshold: string };

function rowsOf(draft: RuleDraft): Row[] {
  return [
    {
      metric: draft.metric,
      operator: draft.operator ?? DEFAULT_OPERATOR[draft.metric],
      threshold: draft.threshold === null ? '' : String(draft.threshold),
    },
    ...(draft.conditions ?? []).map((c: RuleCondition) => ({
      metric: c.metric,
      operator: c.operator ?? DEFAULT_OPERATOR[c.metric],
      threshold: c.threshold === null ? '' : String(c.threshold),
    })),
  ];
}

/**
 * The rule builder: Scope → Conditions → Action → Mode → Schedule, then a dry run.
 *
 * Plain choices, no syntax. An action a platform in scope cannot take says "Not supported" beside it; Auto is shown
 * and cannot be chosen until the owner authorises it; ad-group and ad scope are shown and not yet available. The dry
 * run evaluates the draft against live figures and writes nothing.
 */
export function RuleBuilder({
  open,
  onOpenChange,
  initial,
  ruleId,
  scope,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  initial: RuleDraft;
  ruleId?: string | undefined;
  /** The saved rule's client and project, so editing a rule never quietly widens its scope. */
  scope?: { client: Picked | null; project: Picked | null } | undefined;
}) {
  const { t } = useTranslation();
  const create = useCreateRule();
  const update = useUpdateRule();
  const simulate = useSimulate();
  const capabilities = useCapabilities();

  const [name, setName] = useState(initial.name);
  const [client, setClient] = useState<Picked | null>(scope?.client ?? null);
  const [project, setProject] = useState<Picked | null>(scope?.project ?? null);
  const [clientTerm, setClientTerm] = useState('');
  const [projectTerm, setProjectTerm] = useState('');
  const [platforms, setPlatforms] = useState<string[]>(initial.providers ?? []);
  const [campaigns, setCampaigns] = useState<Picked[]>(
    (initial.campaign_ids ?? []).map((id) => ({
      id,
      label: t('automation.builder.savedCampaign', { id: id.slice(0, 8) }),
    })),
  );
  const [campaignTerm, setCampaignTerm] = useState('');
  const [rows, setRows] = useState<Row[]>(rowsOf(initial));
  const [logic, setLogic] = useState<'all' | 'any'>(initial.logic ?? 'all');
  const [windowDays, setWindowDays] = useState(initial.window_days ?? 7);
  const [forDays, setForDays] = useState(initial.for_days ?? 1);
  const [action, setAction] = useState<RuleAction>(initial.action ?? 'alert');
  const [pct, setPct] = useState(initial.action_pct ?? 20);
  const [mode, setMode] = useState<RuleMode>(initial.mode ?? 'recommend');
  const [schedule, setSchedule] = useState<RuleSchedule>(initial.schedule ?? 'hourly');
  const [time, setTime] = useState(initial.schedule_time ?? '08:00');
  const [cooldown, setCooldown] = useState(initial.cooldown_hours ?? 24);
  const [minSpend, setMinSpend] = useState(
    initial.min_spend == null ? '' : String(initial.min_spend),
  );
  const [minAge, setMinAge] = useState(
    initial.min_age_days == null ? '' : String(initial.min_age_days),
  );
  const [minResults, setMinResults] = useState(
    initial.min_conversions == null ? '' : String(initial.min_conversions),
  );

  const clients = useClientsQuery({ search: clientTerm, per_page: 8 });
  const projects = useProjectsQuery({
    search: projectTerm,
    per_page: 8,
    ...(client === null ? {} : { client_id: client.id }),
  });
  const campaignSearch = useQuery({
    queryKey: ['rule-builder', 'campaigns', campaignTerm] as const,
    queryFn: async (): Promise<Picked[]> =>
      (
        await api.get<{ data: Picked[] }>('/search', {
          params: { q: campaignTerm, type: ['campaign'], limit: 8 },
        })
      ).data.data,
    enabled: campaignTerm.trim().length >= 2,
    retry: false,
  });

  const draft = (): RuleDraft => {
    const [first, ...rest] = rows;
    const value = (r: Row) => (NO_THRESHOLD.has(r.metric) ? null : Number(r.threshold));

    return {
      name: name.trim(),
      metric: first!.metric,
      operator: first!.operator,
      threshold: value(first!),
      conditions: rest.map((r) => ({
        metric: r.metric,
        operator: r.operator,
        threshold: value(r),
      })),
      logic,
      window_days: windowDays,
      for_days: forDays,
      action: mode === 'monitor' ? 'alert' : action,
      action_pct: PCT_ACTIONS.has(action) && mode !== 'monitor' ? pct : null,
      mode,
      severity: mode === 'monitor' ? 'needs_attention' : 'critical',
      is_active: true,
      client_id: project === null ? (client?.id ?? null) : null,
      project_id: project?.id ?? null,
      providers: platforms,
      campaign_ids: campaigns.map((c) => c.id),
      schedule,
      schedule_time: schedule === 'daily' ? time : null,
      timezone: 'Asia/Riyadh',
      cooldown_hours: cooldown,
      min_spend: minSpend === '' ? null : Number(minSpend),
      min_age_days: minAge === '' ? null : Number(minAge),
      min_conversions: minResults === '' ? null : Number(minResults),
    };
  };

  const valid =
    name.trim() !== '' &&
    rows.every(
      (r) =>
        NO_THRESHOLD.has(r.metric) || (r.threshold !== '' && !Number.isNaN(Number(r.threshold))),
    ) &&
    mode !== 'auto';

  const scoped = (capabilities.data ?? []).filter(
    (c) => platforms.length === 0 || platforms.includes(c.provider),
  );
  /** Platforms in scope, grouped by what they would say to this action — so nothing is promised that will not happen. */
  const statesOf = (a: RuleAction) => {
    const groups: Partial<Record<CapabilityState, string[]>> = {};

    for (const c of scoped) {
      const state = c.actions[a];

      if (state !== 'supported') (groups[state] ??= []).push(c.label);
    }

    return groups;
  };
  /* A bid change is wired on no platform: it is shown so it can be named, and cannot be chosen. */
  const nowhere = (a: RuleAction) =>
    scoped.length > 0 && scoped.every((c) => c.actions[a] === 'unsupported');
  const save = ruleId === undefined ? create : update;
  const setRow = (index: number, patch: Partial<Row>) =>
    setRows((all) => all.map((r, i) => (i === index ? { ...r, ...patch } : r)));

  return (
    <ContextDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={ruleId === undefined ? t('automation.builder.title') : t('automation.builder.edit')}
      footer={
        <div className="flex flex-wrap items-center gap-2">
          <Button
            disabled={!valid || save.isPending}
            onClick={() =>
              ruleId === undefined
                ? create.mutate(draft(), { onSuccess: () => onOpenChange(false) })
                : update.mutate(
                    { id: ruleId, draft: draft() },
                    { onSuccess: () => onOpenChange(false) },
                  )
            }
          >
            {t('automation.builder.save')}
          </Button>
          <Button
            variant="secondary"
            disabled={!valid || simulate.isPending}
            onClick={() => simulate.mutate(draft())}
          >
            {t('automation.builder.testNow')}
          </Button>
        </div>
      }
    >
      <div className="flex flex-col gap-6">
        <Input
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder={t('automation.builder.name')}
          aria-label={t('automation.builder.name')}
          dir="auto"
        />

        <Step n={1} title={t('automation.builder.scope')}>
          <EntityPicker
            label={t('automation.filter.client')}
            clearLabel={t('actions.clear')}
            value={client}
            onChange={(o) => {
              setClient(o === null ? null : { id: o.id, label: o.label });
              setProject(null);
            }}
            onSearch={setClientTerm}
            options={(clients.data?.data ?? []).map((c) => ({ id: c.id, label: c.name }))}
            isLoading={clients.isFetching}
          />
          <EntityPicker
            label={t('automation.filter.project')}
            clearLabel={t('actions.clear')}
            value={project}
            onChange={(o) => setProject(o === null ? null : { id: o.id, label: o.label })}
            onSearch={setProjectTerm}
            options={(projects.data?.data ?? []).map((p) => ({ id: p.id, label: p.name }))}
            isLoading={projects.isFetching}
          />
          <div
            className="flex flex-wrap gap-2"
            role="group"
            aria-label={t('automation.filter.platform')}
          >
            {PLATFORMS.map((p) => {
              const on = platforms.includes(p);

              return (
                <button
                  key={p}
                  type="button"
                  aria-pressed={on}
                  onClick={() =>
                    setPlatforms(on ? platforms.filter((x) => x !== p) : [...platforms, p])
                  }
                  className={cn(
                    'rounded-full px-3 py-1.5 text-xs font-semibold',
                    on
                      ? 'bg-action text-action-contrast'
                      : 'bg-surface-sunken text-content-secondary',
                  )}
                >
                  {providerName(p)}
                </button>
              );
            })}
          </div>
          <EntityPicker
            label={t('automation.builder.campaigns')}
            clearLabel={t('actions.clear')}
            value={null}
            onChange={(o) =>
              o !== null &&
              !campaigns.some((c) => c.id === o.id) &&
              setCampaigns([...campaigns, { id: o.id, label: o.label }])
            }
            onSearch={setCampaignTerm}
            options={campaignSearch.data ?? []}
            isLoading={campaignSearch.isFetching}
          />
          {campaigns.length === 0 ? null : (
            <ul className="flex flex-wrap gap-1.5">
              {campaigns.map((c) => (
                <li
                  key={c.id}
                  className="bg-surface-sunken flex items-center gap-1 rounded-full py-1 ps-3 pe-1 text-xs"
                >
                  <span dir="auto">{c.label}</span>
                  <button
                    type="button"
                    aria-label={t('actions.clear')}
                    onClick={() => setCampaigns(campaigns.filter((x) => x.id !== c.id))}
                    className="rounded-full p-0.5"
                  >
                    <X aria-hidden className="size-3" />
                  </button>
                </li>
              ))}
            </ul>
          )}
          <p className="text-content-tertiary flex items-center gap-1.5 text-xs">
            <CircleSlash aria-hidden className="size-3" />
            {t('automation.builder.levelsSoon')}
          </p>
        </Step>

        <Step n={2} title={t('automation.builder.conditions')}>
          {rows.map((row, index) => (
            <div key={index} className="flex flex-wrap items-center gap-2">
              <span className="text-content-tertiary w-10 text-xs font-semibold">
                {index === 0 ? t('automation.when') : t(`automation.logic.${logic}`)}
              </span>
              <select
                className={SELECT}
                value={row.metric}
                aria-label={t('automation.builder.metric')}
                onChange={(e) => {
                  const metric = e.target.value as RuleMetric;
                  setRow(index, { metric, operator: DEFAULT_OPERATOR[metric] });
                }}
              >
                {METRICS.map((m) => (
                  <option key={m} value={m}>
                    {t(`automation.short.${m}`)}
                  </option>
                ))}
              </select>
              {NO_THRESHOLD.has(row.metric) ? null : (
                <>
                  {/* LTR: in a right-to-left page the < and > glyphs are mirrored, which would invert the meaning. */}
                  <select
                    dir="ltr"
                    className={SELECT}
                    value={row.operator}
                    aria-label={t('automation.builder.operator')}
                    onChange={(e) => setRow(index, { operator: e.target.value as Operator })}
                  >
                    <option value="above">&gt;</option>
                    <option value="below">&lt;</option>
                  </select>
                  <Input
                    className="w-28"
                    inputMode="decimal"
                    value={row.threshold}
                    aria-label={t('automation.builder.limit')}
                    onChange={(e) => setRow(index, { threshold: e.target.value })}
                  />
                </>
              )}
              {index === 0 ? null : (
                <Button
                  variant="ghost"
                  size="sm"
                  aria-label={t('automation.builder.removeCondition')}
                  onClick={() => setRows(rows.filter((_, i) => i !== index))}
                >
                  <Trash2 />
                </Button>
              )}
            </div>
          ))}
          <div className="flex flex-wrap items-center gap-2">
            {rows.length < 4 ? (
              <Button
                variant="ghost"
                size="sm"
                onClick={() =>
                  setRows([...rows, { metric: 'roas', operator: 'below', threshold: '' }])
                }
              >
                <Plus />
                {t('automation.builder.addCondition')}
              </Button>
            ) : null}
            {rows.length > 1 ? (
              <div
                className="bg-surface-sunken flex rounded-full p-1"
                role="group"
                aria-label={t('automation.builder.logic')}
              >
                {(['all', 'any'] as const).map((l) => (
                  <button
                    key={l}
                    type="button"
                    aria-pressed={logic === l}
                    onClick={() => setLogic(l)}
                    className={cn(
                      'rounded-full px-3 py-1 text-xs font-semibold',
                      logic === l ? 'bg-action text-action-contrast' : 'text-content-secondary',
                    )}
                  >
                    {t(`automation.logic.${l}`)}
                  </button>
                ))}
              </div>
            ) : null}
          </div>
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <span className="text-content-tertiary text-xs">{t('automation.builder.window')}</span>
            <select
              className={SELECT}
              value={windowDays}
              onChange={(e) => setWindowDays(Number(e.target.value))}
              aria-label={t('automation.builder.window')}
            >
              {[1, 3, 7, 14, 28].map((d) => (
                <option key={d} value={d}>
                  {t('automation.builder.lastDays', { count: d })}
                </option>
              ))}
            </select>
            <span className="text-content-tertiary text-xs">{t('automation.for')}</span>
            <select
              className={SELECT}
              value={forDays}
              onChange={(e) => setForDays(Number(e.target.value))}
              aria-label={t('automation.for')}
            >
              {[1, 2, 3, 5, 7].map((d) => (
                <option key={d} value={d}>
                  {t('automation.days', { count: d })}
                </option>
              ))}
            </select>
          </div>
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <span className="text-content-tertiary text-xs">
              {t('automation.builder.onlyWhen')}
            </span>
            <Input
              className="w-28"
              inputMode="decimal"
              placeholder={t('automation.builder.minSpend')}
              aria-label={t('automation.builder.minSpend')}
              value={minSpend}
              onChange={(e) => setMinSpend(e.target.value)}
            />
            <Input
              className="w-24"
              inputMode="numeric"
              placeholder={t('automation.builder.minAge')}
              aria-label={t('automation.builder.minAge')}
              value={minAge}
              onChange={(e) => setMinAge(e.target.value)}
            />
            <Input
              className="w-28"
              inputMode="decimal"
              placeholder={t('automation.builder.minResults')}
              aria-label={t('automation.builder.minResults')}
              value={minResults}
              onChange={(e) => setMinResults(e.target.value)}
            />
          </div>
        </Step>

        <Step n={3} title={t('automation.builder.action')}>
          <div className="grid gap-2 sm:grid-cols-2">
            {ACTIONS.map((a) => {
              const states = statesOf(a);

              return (
                <button
                  key={a}
                  type="button"
                  aria-pressed={action === a}
                  disabled={nowhere(a)}
                  onClick={() => setAction(a)}
                  className={cn(
                    'flex flex-col items-start gap-0.5 rounded-2xl p-3 text-start text-sm disabled:cursor-not-allowed disabled:opacity-60',
                    action === a
                      ? 'bg-action text-action-contrast'
                      : 'bg-surface-sunken text-content',
                  )}
                >
                  <span className="font-semibold">
                    {actionText(t, a, PCT_ACTIONS.has(a) ? pct : null)}
                  </span>
                  {nowhere(a) ? (
                    <span className="text-xs">{t('automation.notSupported')}</span>
                  ) : (
                    (Object.entries(states) as Array<[CapabilityState, string[]]>).map(
                      ([state, labels]) => (
                        <span
                          key={state}
                          className={cn('text-xs', action === a ? 'opacity-80' : 'text-warning')}
                        >
                          {t(`automation.capability.${state}`, { platforms: labels.join(', ') })}
                        </span>
                      ),
                    )
                  )}
                </button>
              );
            })}
          </div>
          {PCT_ACTIONS.has(action) ? (
            <select
              className={SELECT}
              value={pct}
              onChange={(e) => setPct(Number(e.target.value))}
              aria-label={t('automation.builder.cut')}
            >
              {[5, 10, 15, 20, 25, 30].map((p) => (
                <option key={p} value={p}>
                  {p}%
                </option>
              ))}
            </select>
          ) : null}
        </Step>

        <Step n={4} title={t('automation.builder.mode')}>
          <div className="grid gap-2 sm:grid-cols-3">
            {(['monitor', 'recommend', 'auto'] as const).map((m) => (
              <button
                key={m}
                type="button"
                aria-pressed={mode === m}
                disabled={m === 'auto'}
                onClick={() => setMode(m)}
                className={cn(
                  'flex flex-col items-start gap-0.5 rounded-2xl p-3 text-start text-sm disabled:cursor-not-allowed disabled:opacity-60',
                  mode === m ? 'bg-action text-action-contrast' : 'bg-surface-sunken text-content',
                )}
              >
                <span className="font-semibold">{t(`automation.mode.${m}`)}</span>
                <span className="text-xs opacity-80">{t(`automation.modeHint.${m}`)}</span>
              </button>
            ))}
          </div>
        </Step>

        <Step n={5} title={t('automation.builder.schedule')}>
          <div className="flex flex-wrap items-center gap-2">
            {(['continuous', 'hourly', 'daily'] as const).map((s) => (
              <button
                key={s}
                type="button"
                aria-pressed={schedule === s}
                onClick={() => setSchedule(s)}
                className={cn(
                  'rounded-full px-3 py-1.5 text-xs font-semibold',
                  schedule === s
                    ? 'bg-action text-action-contrast'
                    : 'bg-surface-sunken text-content-secondary',
                )}
              >
                {t(`automation.schedule.${s}`)}
              </button>
            ))}
            {schedule === 'daily' ? (
              <Input
                type="time"
                className="w-32"
                value={time}
                onChange={(e) => setTime(e.target.value)}
                aria-label={t('automation.builder.time')}
              />
            ) : null}
            <span className="text-content-tertiary text-xs">{t('automation.builder.riyadh')}</span>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-content-tertiary text-xs">
              {t('automation.builder.cooldown')}
            </span>
            <select
              className={SELECT}
              value={cooldown}
              onChange={(e) => setCooldown(Number(e.target.value))}
              aria-label={t('automation.builder.cooldown')}
            >
              {[6, 12, 24, 48, 72, 168].map((h) => (
                <option key={h} value={h}>
                  {t('automation.builder.hours', { count: h })}
                </option>
              ))}
            </select>
          </div>
        </Step>

        <section
          aria-label={t('automation.builder.summary')}
          className="bg-surface-sunken flex flex-col gap-1 rounded-2xl p-4 text-sm"
        >
          {rows.map((r, i) => {
            const part = conditionParts(
              t,
              r.metric,
              r.operator,
              r.threshold === '' ? null : Number(r.threshold),
            );

            return (
              <span key={i} className="text-content">
                {i === 0 ? '' : `${t(`automation.logic.${logic}`)} `}
                {part.name}
                {part.comparison === null ? null : (
                  <>
                    {' '}
                    <bdi dir="ltr" className="ds-numeric">
                      {part.comparison}
                    </bdi>
                  </>
                )}
              </span>
            );
          })}
          <span className="text-content-tertiary" aria-hidden>
            ↓
          </span>
          <span className="text-content font-semibold">
            {mode === 'monitor'
              ? actionText(t, 'notify', null)
              : actionText(t, action, PCT_ACTIONS.has(action) ? pct : null)}
          </span>
        </section>

        {save.isError ? <Alert tone="danger">{t('automation.failed')}</Alert> : null}
        {simulate.data === undefined ? null : (
          <section aria-label={t('automation.builder.testNow')} className="flex flex-col gap-2">
            <dl className="grid grid-cols-3 gap-2 text-center">
              {(
                [
                  ['matched', simulate.data.matched],
                  ['wouldAct', simulate.data.would_act],
                  ['insufficient', simulate.data.insufficient],
                ] as const
              ).map(([key, value]) => (
                <div key={key} className="bg-surface-sunken rounded-xl p-2">
                  <dt className="text-content-tertiary text-xs">{t(`automation.dry.${key}`)}</dt>
                  <dd className="ds-numeric text-content text-lg font-semibold">{value}</dd>
                </div>
              ))}
            </dl>
            {Object.keys(simulate.data.skipped).length === 0 ? null : (
              <ul className="flex flex-wrap gap-1.5" aria-label={t('automation.dry.skipped')}>
                {Object.entries(simulate.data.skipped).map(([reason, count]) => (
                  <li
                    key={reason}
                    className="bg-warning-surface text-warning rounded-full px-2.5 py-0.5 text-xs font-semibold"
                  >
                    {t(`automation.skip.${reason}`, { count })}
                  </li>
                ))}
              </ul>
            )}
            <ul className="flex flex-col gap-1.5">
              {simulate.data.objects.slice(0, 8).map((o) => (
                <li
                  key={o.campaign_id}
                  className="bg-surface-sunken flex items-center justify-between gap-2 rounded-xl px-3 py-2 text-xs"
                >
                  <span className="min-w-0 truncate" dir="auto">
                    {o.campaign}
                  </span>
                  <span
                    className={cn(
                      'shrink-0 font-semibold',
                      o.outcome === 'would_act' ? 'text-success' : 'text-warning',
                    )}
                  >
                    {o.outcome === 'would_act'
                      ? actionText(
                          t,
                          o.action as RuleAction | 'notify',
                          PCT_ACTIONS.has(action) ? pct : null,
                        )
                      : t(`automation.skip.${o.outcome}`, { count: 1 })}
                  </span>
                </li>
              ))}
            </ul>
            <p className="text-content-tertiary text-xs">{t('automation.dry.nothingChanged')}</p>
          </section>
        )}
      </div>
    </ContextDrawer>
  );
}

function Step({ n, title, children }: { n: number; title: string; children: React.ReactNode }) {
  return (
    <section className="flex flex-col gap-3">
      <h3 className="text-content flex items-center gap-2 text-sm font-semibold">
        <span className="bg-action text-action-contrast ds-numeric grid size-6 place-items-center rounded-full text-xs">
          {n}
        </span>
        {title}
      </h3>
      {children}
    </section>
  );
}
