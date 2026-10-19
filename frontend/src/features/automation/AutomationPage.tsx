import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { Pencil, Plus, Search, Trash2, Zap } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { cn } from '@/shared/lib/cn';
import { formatNumber } from '@/shared/lib/format';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { providerName } from '@/shared/lib/providers';
import { useUiStore } from '@/shared/store/ui-store';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Input } from '@/shared/ui/input';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import {
  DataTable,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/ui/table';
import {
  useAutomationSummary,
  useBulkRules,
  useDeleteRule,
  useRulesQuery,
  useUpdateRule,
} from './api';
import { RuleBuilder } from './RuleBuilder';
import { actionText, ruleConditionParts } from './ruleText';
import { COVERED, TEMPLATES, draftFrom } from './templates';
import { draftOf, type Rule, type RuleDraft, type RuleMode } from './types';

const MODE_TONE: Record<RuleMode, string> = {
  monitor: 'bg-surface-sunken text-content-secondary',
  recommend: 'bg-accent-subtle text-content-brand',
  auto: 'bg-warning-surface text-warning',
};

const CARDS = ['all', 'turn_on', 'turn_off', 'budget', 'bid', 'failed'] as const;

const BLANK: RuleDraft = {
  name: '',
  metric: 'spend',
  operator: 'above',
  threshold: 500,
  conditions: [{ metric: 'roas', operator: 'below', threshold: 3 }],
  window_days: 3,
  action: 'propose_budget_cut',
  action_pct: 20,
  mode: 'recommend',
  schedule: 'hourly',
};

/**
 * Rules — the automation dashboard.
 *
 * What the rules did over the period (with the change against the period before), then every rule as one row: on or
 * off, its scope, its conditions as short lines, its action, its mode, when it last ran and what came of it. A rule is
 * created from a template or from blank in the builder, and dry-run before it is saved.
 */
export function AutomationPage() {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const mayWrite = can(PERMISSIONS.budgetsManage);
  const context = useUiStore((s) => s.context);
  const [days, setDays] = useState(7);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<'all' | 'on' | 'off'>('all');
  const [mode, setMode] = useState<'all' | RuleMode>('all');
  const [platform, setPlatform] = useState('all');
  const [builder, setBuilder] = useState<{ draft: RuleDraft; rule?: Rule } | null>(null);
  const [picked, setPicked] = useState<Set<string>>(new Set());
  const bulk = useBulkRules();
  const pick = (id: string) =>
    setPicked((now) => {
      const next = new Set(now);

      if (next.has(id)) next.delete(id);
      else next.add(id);

      return next;
    });

  const rules = useRulesQuery();
  const summary = useAutomationSummary(days);
  const update = useUpdateRule();
  const remove = useDeleteRule();

  const scope = {
    client_id: context.project === null ? (context.client?.id ?? null) : null,
    project_id: context.project?.id ?? null,
  };

  const shown = useMemo(
    () =>
      (rules.data ?? []).filter(
        (r) =>
          (status === 'all' || (status === 'on') === r.is_active) &&
          (mode === 'all' || r.mode === mode) &&
          (platform === 'all' || r.providers.length === 0 || r.providers.includes(platform)) &&
          (search.trim() === '' || r.name.toLowerCase().includes(search.trim().toLowerCase())) &&
          (context.client === null || r.client === null || r.client.id === context.client.id) &&
          (context.project === null || r.project === null || r.project.id === context.project.id),
      ),
    [rules.data, status, mode, platform, search, context],
  );

  return (
    <div className="ds-page">
      <PageHeader
        title={t('automation.rules')}
        primaryAction={
          mayWrite ? (
            <Button onClick={() => setBuilder({ draft: { ...BLANK, ...scope } })}>
              <Plus />
              {t('automation.newRule')}
            </Button>
          ) : undefined
        }
      />

      <section aria-label={t('automation.summary')} className="flex flex-col gap-3">
        <div className="flex items-center justify-between gap-2">
          <h2 className="text-content text-base font-semibold">{t('automation.summary')}</h2>
          <select
            aria-label={t('automation.filter.period')}
            value={days}
            onChange={(e) => setDays(Number(e.target.value))}
            className="bg-surface text-content-secondary h-9 rounded-full px-3 text-xs font-medium shadow-xs"
          >
            {[1, 7, 30, 90].map((d) => (
              <option key={d} value={d}>
                {t('automation.lastDays', { count: d })}
              </option>
            ))}
          </select>
        </div>
        <ul className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
          {CARDS.map((key) => {
            const card = summary.data?.cards[key];
            const diff = card === undefined ? 0 : card.now - card.before;

            return (
              <li key={key} className="bg-surface flex flex-col gap-1 rounded-2xl p-4 shadow-xs">
                <span className="text-content-tertiary text-xs font-medium">
                  {t(`automation.cards.${key}`)}
                </span>
                <span className="ds-numeric text-content text-2xl font-semibold">
                  {card === undefined ? '—' : formatNumber(card.now, i18n.language)}
                </span>
                <span className="text-content-tertiary text-xs">
                  {t('automation.vsBefore')}{' '}
                  <span
                    className={cn(
                      'ds-numeric font-semibold',
                      diff > 0 ? 'text-content' : 'text-content-tertiary',
                    )}
                  >
                    {diff > 0 ? '+' : ''}
                    {formatNumber(diff, i18n.language)}
                  </span>
                </span>
              </li>
            );
          })}
          <li className="bg-surface flex flex-col gap-1 rounded-2xl p-4 shadow-xs">
            <span className="text-content-tertiary text-xs font-medium">
              {t('automation.cards.inactive')}
            </span>
            <span className="ds-numeric text-content text-2xl font-semibold">
              {summary.data === undefined
                ? '—'
                : formatNumber(summary.data.rules.inactive_30d, i18n.language)}
            </span>
            <span className="text-content-tertiary text-xs">
              {summary.data === undefined
                ? ''
                : t('automation.ofActive', { count: summary.data.rules.active })}
            </span>
          </li>
        </ul>
      </section>

      <section aria-label={t('automation.rules')} className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative min-w-56 flex-1">
            <Search
              aria-hidden
              className="text-content-tertiary absolute start-3 top-1/2 size-4 -translate-y-1/2"
            />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t('automation.filter.search')}
              aria-label={t('automation.filter.search')}
              className="ps-9"
            />
          </div>
          <FilterSelect
            label={t('automation.filter.status')}
            value={status}
            onChange={(v) => setStatus(v as typeof status)}
            options={[
              ['all', t('automation.filter.anyStatus')],
              ['on', t('automation.on')],
              ['off', t('automation.off')],
            ]}
          />
          <FilterSelect
            label={t('automation.filter.mode')}
            value={mode}
            onChange={(v) => setMode(v as typeof mode)}
            options={[
              ['all', t('automation.filter.anyMode')],
              ['monitor', t('automation.mode.monitor')],
              ['recommend', t('automation.mode.recommend')],
            ]}
          />
          <FilterSelect
            label={t('automation.filter.platform')}
            value={platform}
            onChange={setPlatform}
            options={[
              ['all', t('automation.filter.anyPlatform')],
              ...['meta', 'google_ads', 'tiktok', 'snapchat', 'linkedin', 'x'].map(
                (p) => [p, providerName(p)] as [string, string],
              ),
            ]}
          />
        </div>

        {rules.isError ? <Alert tone="danger">{t('automation.failed')}</Alert> : null}
        {rules.isPending ? (
          <Skeleton className="h-48 rounded-3xl" />
        ) : shown.length === 0 ? (
          <p className="text-content-tertiary bg-surface rounded-2xl p-6 text-sm">
            {t('automation.none')}
          </p>
        ) : (
          <DataTable label={t('automation.rules')} minWidth="72rem">
            <TableHead>
              <TableRow>
                {mayWrite ? (
                  <TableHeader>
                    <input
                      type="checkbox"
                      aria-label={t('automation.bulk.selectAll')}
                      className="accent-action size-4"
                      checked={shown.length > 0 && shown.every((r) => picked.has(r.id))}
                      onChange={(e) =>
                        setPicked(e.target.checked ? new Set(shown.map((r) => r.id)) : new Set())
                      }
                    />
                  </TableHeader>
                ) : null}
                <TableHeader>{t('automation.col.onOff')}</TableHeader>
                <TableHeader>{t('automation.col.name')}</TableHeader>
                <TableHeader>{t('automation.col.scope')}</TableHeader>
                <TableHeader>{t('automation.col.conditions')}</TableHeader>
                <TableHeader>{t('automation.col.action')}</TableHeader>
                <TableHeader>{t('automation.col.object')}</TableHeader>
                <TableHeader>{t('automation.col.mode')}</TableHeader>
                <TableHeader>{t('automation.col.lastRun')}</TableHeader>
                <TableHeader>{t('automation.col.lastResult')}</TableHeader>
                {/* A visible label: an sr-only one is positioned outside the scroll region and widens the page in RTL. */}
                <TableHeader>{t('automation.col.edit')}</TableHeader>
              </TableRow>
            </TableHead>
            <TableBody>
              {shown.map((rule) => (
                <TableRow key={rule.id}>
                  {mayWrite ? (
                    <TableCell>
                      <input
                        type="checkbox"
                        aria-label={t('automation.bulk.select', { name: rule.name })}
                        className="accent-action size-4"
                        checked={picked.has(rule.id)}
                        onChange={() => pick(rule.id)}
                      />
                    </TableCell>
                  ) : null}
                  <TableCell>
                    <button
                      type="button"
                      role="switch"
                      aria-checked={rule.is_active}
                      aria-label={t('automation.toggle', { name: rule.name })}
                      disabled={!mayWrite || update.isPending}
                      onClick={() =>
                        update.mutate({
                          id: rule.id,
                          draft: { ...draftOf(rule), is_active: !rule.is_active },
                        })
                      }
                      className={cn(
                        'relative h-6 w-11 shrink-0 rounded-full transition-colors',
                        rule.is_active ? 'bg-accent-strong' : 'bg-surface-active',
                      )}
                    >
                      <span
                        aria-hidden
                        className={cn(
                          'absolute top-0.5 size-5 rounded-full bg-white shadow-xs transition-all',
                          rule.is_active ? 'start-[1.375rem]' : 'start-0.5',
                        )}
                      />
                    </button>
                  </TableCell>
                  <TableCell>
                    <span className="text-content font-medium" dir="auto">
                      {rule.name}
                    </span>
                  </TableCell>
                  <TableCell>
                    <span className="text-content-secondary flex flex-col text-xs" dir="auto">
                      <span>
                        {rule.project?.name ?? rule.client?.name ?? t('automation.everywhere')}
                      </span>
                      <span className="text-content-tertiary">
                        {rule.providers.length === 0
                          ? t('automation.filter.anyPlatform')
                          : rule.providers.map(providerName).join(' · ')}
                        {rule.campaign_ids.length === 0
                          ? ''
                          : ` · ${t('automation.campaignCount', { count: rule.campaign_ids.length })}`}
                      </span>
                    </span>
                  </TableCell>
                  <TableCell>
                    <span className="flex flex-col gap-0.5 text-xs">
                      {ruleConditionParts(t, rule).map((part, i) => (
                        <span key={i} className="text-content">
                          {i === 0 ? null : (
                            <span className="text-content-tertiary">
                              {t(`automation.logic.${rule.logic}`)}{' '}
                            </span>
                          )}
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
                      ))}
                    </span>
                  </TableCell>
                  <TableCell>
                    <span className="text-content text-sm">
                      {rule.mode === 'monitor'
                        ? actionText(t, 'notify', null)
                        : actionText(t, rule.action, rule.action_pct)}
                    </span>
                  </TableCell>
                  <TableCell>
                    <span className="text-content-secondary text-xs">
                      {t(`automation.object.${rule.object_level}`)}
                    </span>
                  </TableCell>
                  <TableCell>
                    <span
                      className={cn(
                        'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                        MODE_TONE[rule.mode],
                      )}
                    >
                      {t(`automation.mode.${rule.mode}`)}
                    </span>
                  </TableCell>
                  <TableCell>
                    <span className="text-content-tertiary text-xs">
                      {rule.last_run_at === null
                        ? t('automation.notRunYet')
                        : formatRelativeTime(rule.last_run_at, i18n.language)}
                    </span>
                  </TableCell>
                  <TableCell>
                    <LastResult rule={rule} />
                  </TableCell>
                  <TableCell>
                    {mayWrite ? (
                      <span className="flex gap-1">
                        <Button
                          variant="ghost"
                          size="sm"
                          aria-label={t('automation.edit', { name: rule.name })}
                          onClick={() => setBuilder({ draft: draftOf(rule), rule })}
                        >
                          <Pencil />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          aria-label={t('automation.remove', { name: rule.name })}
                          disabled={remove.isPending}
                          onClick={() => remove.mutate(rule.id)}
                        >
                          <Trash2 />
                        </Button>
                      </span>
                    ) : null}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </DataTable>
        )}
      </section>

      {picked.size === 0 ? null : (
        <div
          role="region"
          aria-label={t('automation.bulk.title')}
          className="bg-action text-action-contrast sticky bottom-4 z-20 flex flex-wrap items-center gap-2 rounded-3xl p-3 shadow-lg"
        >
          <span className="ds-numeric px-2 text-sm font-semibold">
            {t('automation.bulk.selected', { count: picked.size })}
          </span>
          {(
            [
              ['enable', undefined],
              ['disable', undefined],
              ['mode', 'monitor'],
              ['mode', 'recommend'],
              ['archive', undefined],
            ] as const
          ).map(([operation, mode]) => (
            <Button
              key={`${operation}-${mode ?? ''}`}
              variant="secondary"
              size="sm"
              disabled={bulk.isPending}
              onClick={() =>
                bulk.mutate(
                  { ids: [...picked], operation, ...(mode === undefined ? {} : { mode }) },
                  { onSuccess: () => setPicked(new Set()) },
                )
              }
            >
              {mode === undefined
                ? t(`automation.bulk.${operation}`)
                : t('automation.bulk.setMode', { mode: t(`automation.mode.${mode}`) })}
            </Button>
          ))}
          {bulk.data !== undefined && bulk.data.refused > 0 ? (
            <span role="status" className="text-xs">
              {t('automation.bulk.refused', { count: bulk.data.refused })}
            </span>
          ) : null}
        </div>
      )}

      <section aria-label={t('automation.templates')} className="flex flex-col gap-3">
        <h2 className="text-content text-base font-semibold">{t('automation.templates')}</h2>
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {TEMPLATES.map((template) => (
            <li
              key={template.key}
              className="bg-surface flex flex-col gap-2 rounded-2xl p-4 shadow-xs"
            >
              <span className="text-content flex items-center gap-2 text-sm font-semibold">
                <Zap aria-hidden className="text-content-brand size-4" />
                {t(`automation.template.${template.key}`)}
              </span>
              <Button
                variant="secondary"
                size="sm"
                className="mt-auto self-start"
                disabled={!mayWrite}
                onClick={() =>
                  setBuilder({
                    draft: draftFrom(template, t(`automation.template.${template.key}`), scope),
                  })
                }
              >
                {t('automation.useTemplate')}
              </Button>
            </li>
          ))}
          {COVERED.map((c) => (
            <li
              key={c.key}
              className="bg-surface-sunken text-content-tertiary flex flex-col gap-1 rounded-2xl p-4"
            >
              <span className="text-sm font-semibold">{t(`automation.template.${c.key}`)}</span>
              <Link
                to={c.by === 'creative' ? '/automation/agents' : '/tracking'}
                className="text-xs underline"
              >
                {t(`automation.covered.${c.by}`)}
              </Link>
            </li>
          ))}
        </ul>
      </section>

      {builder === null ? null : (
        <RuleBuilder
          key={builder.rule?.id ?? builder.draft.name}
          open
          onOpenChange={(open) => !open && setBuilder(null)}
          initial={builder.draft}
          ruleId={builder.rule?.id}
          scope={
            builder.rule === undefined
              ? undefined
              : {
                  client:
                    builder.rule.client === null
                      ? null
                      : { id: builder.rule.client.id, label: builder.rule.client.name },
                  project:
                    builder.rule.project === null
                      ? null
                      : { id: builder.rule.project.id, label: builder.rule.project.name },
                }
          }
        />
      )}
    </div>
  );
}

function LastResult({ rule }: { rule: Rule }) {
  const { t } = useTranslation();
  const r = rule.last_result;

  if (r === null)
    return <span className="text-content-tertiary text-xs">{t('automation.notRunYet')}</span>;

  const acted = (r.completed ?? 0) + (r.waiting_approval ?? 0);
  const refused = (r.unsupported ?? 0) + (r.failed ?? 0);

  return (
    <span className="flex flex-col text-xs">
      {acted > 0 ? (
        <span className="text-content">{t('automation.result.acted', { count: acted })}</span>
      ) : (
        <span className="text-content-tertiary">{t('automation.result.noChange')}</span>
      )}
      {refused > 0 ? (
        <Link to={`/automation/history?rule=${rule.id}`} className="text-warning underline">
          {t('automation.result.unable', { count: refused })}
        </Link>
      ) : null}
    </span>
  );
}

function FilterSelect({
  label,
  value,
  onChange,
  options,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  options: Array<[string, string]>;
}) {
  return (
    <select
      aria-label={label}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="bg-surface text-content-secondary h-10 rounded-full px-3 text-sm shadow-xs"
    >
      {options.map(([v, l]) => (
        <option key={v} value={v}>
          {l}
        </option>
      ))}
    </select>
  );
}
