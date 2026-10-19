import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Plus } from 'lucide-react';
import { Button } from '@/shared/ui/button';
import { Input } from '@/shared/ui/input';
import { useCreateRule } from './api';
import {
  MIN_TRUST,
  NO_THRESHOLD,
  type RuleAction,
  type RuleMetric,
  type TrustLevel,
} from './types';

const METRICS: RuleMetric[] = [
  'cost_per_result',
  'roas',
  'budget_used',
  'no_results',
  'rejected',
  'ends_within',
];
const ACTIONS: RuleAction[] = ['alert', 'propose_budget_cut', 'propose_pause', 'task'];
const SELECT = 'bg-surface-sunken text-content h-10 rounded-full px-3 text-sm';

/**
 * WHEN · AND · FOR · THEN, in one row of plain choices.
 *
 * The trust level is chosen with the action, and an action the chosen trust does not allow cannot be picked — the
 * server refuses it as well. Level 4 is shown, disabled, so nobody wonders where it is.
 */
export function RuleBuilder({
  scope,
  onDone,
}: {
  scope: { client_id: string | null; project_id: string | null };
  onDone: () => void;
}) {
  const { t } = useTranslation();
  const create = useCreateRule();
  const [name, setName] = useState('');
  const [metric, setMetric] = useState<RuleMetric>('cost_per_result');
  const [threshold, setThreshold] = useState('100');
  const [andMetric, setAndMetric] = useState<RuleMetric | ''>('');
  const [andThreshold, setAndThreshold] = useState('');
  const [forDays, setForDays] = useState(1);
  const [action, setAction] = useState<RuleAction>('alert');
  const [pct, setPct] = useState(15);
  const [trust, setTrust] = useState<TrustLevel>(2);

  const needs = (m: RuleMetric) => !NO_THRESHOLD.has(m);
  const valid =
    name.trim() !== '' &&
    (!needs(metric) || threshold !== '') &&
    (andMetric === '' || !needs(andMetric) || andThreshold !== '') &&
    trust >= MIN_TRUST[action];

  return (
    <form
      className="bg-surface flex flex-col gap-3 rounded-3xl p-5 shadow-xs"
      onSubmit={(event) => {
        event.preventDefault();

        if (!valid) return;

        create.mutate(
          {
            name: name.trim(),
            metric,
            threshold: needs(metric) ? Number(threshold) : null,
            conditions:
              andMetric === ''
                ? []
                : [
                    {
                      metric: andMetric,
                      threshold: needs(andMetric) ? Number(andThreshold) : null,
                    },
                  ],
            for_days: forDays,
            action,
            action_pct: action === 'propose_budget_cut' ? pct : null,
            trust_level: trust,
            severity: action === 'alert' ? 'needs_attention' : 'critical',
            ...scope,
          },
          { onSuccess: onDone },
        );
      }}
    >
      <Input
        value={name}
        onChange={(e) => setName(e.target.value)}
        placeholder={t('automation.builder.name')}
        aria-label={t('automation.builder.name')}
        dir="auto"
      />

      <div className="flex flex-wrap items-center gap-2">
        <span className="text-content-tertiary w-14 text-xs font-semibold">
          {t('automation.when')}
        </span>
        <select
          className={SELECT}
          value={metric}
          onChange={(e) => setMetric(e.target.value as RuleMetric)}
          aria-label={t('automation.when')}
        >
          {METRICS.map((m) => (
            <option key={m} value={m}>
              {t(`automation.metric.${m}`)}
            </option>
          ))}
        </select>
        {needs(metric) ? (
          <Input
            className="w-28"
            inputMode="decimal"
            value={threshold}
            onChange={(e) => setThreshold(e.target.value)}
            aria-label={t('automation.builder.limit')}
          />
        ) : null}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <span className="text-content-tertiary w-14 text-xs font-semibold">
          {t('automation.and')}
        </span>
        <select
          className={SELECT}
          value={andMetric}
          onChange={(e) => setAndMetric(e.target.value as RuleMetric | '')}
          aria-label={t('automation.and')}
        >
          <option value="">{t('automation.builder.nothingElse')}</option>
          {METRICS.filter((m) => m !== metric).map((m) => (
            <option key={m} value={m}>
              {t(`automation.metric.${m}`)}
            </option>
          ))}
        </select>
        {andMetric !== '' && needs(andMetric) ? (
          <Input
            className="w-28"
            inputMode="decimal"
            value={andThreshold}
            onChange={(e) => setAndThreshold(e.target.value)}
            aria-label={t('automation.builder.limit')}
          />
        ) : null}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <span className="text-content-tertiary w-14 text-xs font-semibold">
          {t('automation.for')}
        </span>
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

      <div className="flex flex-wrap items-center gap-2">
        <span className="text-content-tertiary w-14 text-xs font-semibold">
          {t('automation.then')}
        </span>
        <select
          className={SELECT}
          value={action}
          onChange={(e) => {
            const next = e.target.value as RuleAction;
            setAction(next);
            setTrust((current) => (current < MIN_TRUST[next] ? MIN_TRUST[next] : current));
          }}
          aria-label={t('automation.then')}
        >
          {ACTIONS.map((a) => (
            <option key={a} value={a}>
              {a === 'alert' ? t('automation.toToday') : t(`automation.action.${a}`, { pct })}
            </option>
          ))}
        </select>
        {action === 'propose_budget_cut' ? (
          <select
            className={SELECT}
            value={pct}
            onChange={(e) => setPct(Number(e.target.value))}
            aria-label={t('automation.builder.cut')}
          >
            {[5, 10, 15, 20, 25].map((p) => (
              <option key={p} value={p}>
                −{p}%
              </option>
            ))}
          </select>
        ) : null}
        <select
          className={SELECT}
          value={trust}
          onChange={(e) => setTrust(Number(e.target.value) as TrustLevel)}
          aria-label={t('automation.trust.title')}
        >
          {[0, 1, 2, 3, 4].map((level) => (
            <option key={level} value={level} disabled={level === 4 || level < MIN_TRUST[action]}>
              {t('automation.trust.label', { level })} · {t(`automation.trust.${level}`)}
            </option>
          ))}
        </select>
      </div>

      {create.isError ? <p className="text-danger text-sm">{t('automation.failed')}</p> : null}

      <Button type="submit" className="self-start" disabled={!valid || create.isPending}>
        <Plus />
        {t('automation.builder.save')}
      </Button>
    </form>
  );
}
