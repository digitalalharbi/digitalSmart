import { AskAgents } from '@/features/agents';
import { ModelRouting } from './ModelRouting';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Bot, Copy, KeyRound, Play, Trash2 } from 'lucide-react';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { cn } from '@/shared/lib/cn';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Input } from '@/shared/ui/input';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { ChartCard } from '@/shared/ui/viz';
import {
  useAgentOverview,
  useCreateMcpToken,
  useMcpTokens,
  useRevokeMcpToken,
  useRunAgents,
  type AgentRun,
} from './api';

const STATUS_TONE: Record<AgentRun['status'], string> = {
  analysing: 'bg-info-surface text-info',
  preparing: 'bg-info-surface text-info',
  waiting_approval: 'bg-warning-surface text-warning',
  completed: 'bg-success-surface text-success',
  failed: 'bg-danger-surface text-danger',
};

const SUPPORT_TONE = {
  documented: 'bg-success-surface text-success',
  unverified: 'bg-warning-surface text-warning',
  not_supported: 'bg-surface-sunken text-content-tertiary',
} as const;

/**
 * MCP & AI: for administrators only, and the one place the machinery is shown.
 *
 * What the agents last did, the routines that run them, every tool with how often it was used and refused, which
 * platforms can be asked to change what, and the tokens an outside AI client signs in with. Everyday screens show
 * none of this — they show decisions.
 */
export function AiCenterPage() {
  const { t, i18n } = useTranslation();
  const overview = useAgentOverview();
  const run = useRunAgents();
  const tokens = useMcpTokens();
  const create = useCreateMcpToken();
  const revoke = useRevokeMcpToken();
  const [name, setName] = useState('');
  const [fresh, setFresh] = useState<string | null>(null);

  const data = overview.data;
  const lastByAgent = new Map<string, AgentRun>();

  for (const r of data?.runs ?? []) {
    if (!lastByAgent.has(r.agent)) {
      lastByAgent.set(r.agent, r);
    }
  }

  return (
    <div className="ds-page">
      <PageHeader
        title={t('aiCenter.title')}
        primaryAction={
          <Button disabled={run.isPending} onClick={() => run.mutate()}>
            <Play />
            {run.isPending ? t('aiCenter.running') : t('aiCenter.runNow')}
          </Button>
        }
      />

      {overview.isError ? <Alert tone="danger">{t('aiCenter.failed')}</Alert> : null}

      {data === undefined ? (
        <Skeleton className="h-64 rounded-3xl" />
      ) : (
        <>
          <AskAgents />

          <section
            aria-label={t('aiCenter.agents')}
            className="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
          >
            {(['analyst', 'finance', 'creative', 'leads', 'reports', 'operations'] as const).map(
              (agent) => {
                const last = lastByAgent.get(agent);
                /* A specialist leaves findings rather than proposals; its card counts what it still sees. */
                const specialist = agent !== 'analyst' && agent !== 'finance';
                const findings =
                  typeof last?.summary['findings'] === 'number' ? last.summary['findings'] : 0;

                return (
                  <ChartCard key={agent} title={t(`aiCenter.agent.${agent}`)}>
                    <div className="flex items-center gap-3">
                      <span className="bg-accent-subtle text-content-brand grid size-10 place-items-center rounded-full">
                        <Bot aria-hidden className="size-5" />
                      </span>
                      {last === undefined ? (
                        <span className="text-content-tertiary text-sm">{t('aiCenter.never')}</span>
                      ) : (
                        <span className="flex flex-col gap-1">
                          <span
                            className={cn(
                              'w-fit rounded-full px-2.5 py-0.5 text-xs font-semibold',
                              STATUS_TONE[last.status],
                            )}
                          >
                            {t(`aiCenter.status.${last.status}`)}
                          </span>
                          <span className="text-content-tertiary text-xs">
                            {formatRelativeTime(last.started_at, i18n.language)}
                          </span>
                        </span>
                      )}
                    </div>
                    {last === undefined ? null : (
                      <dl className="grid grid-cols-2 gap-3">
                        <div>
                          <dt className="text-content-tertiary text-xs">
                            {t('aiCenter.lookedAt')}
                          </dt>
                          <dd className="ds-numeric text-content text-2xl font-semibold">
                            {last.looked_at}
                          </dd>
                        </div>
                        <div>
                          <dt className="text-content-tertiary text-xs">
                            {specialist ? t('aiCenter.findings') : t('aiCenter.proposed')}
                          </dt>
                          <dd className="ds-numeric text-content text-2xl font-semibold">
                            {specialist ? findings : last.proposed}
                          </dd>
                        </div>
                      </dl>
                    )}
                  </ChartCard>
                );
              },
            )}

            <ChartCard title={t('aiCenter.routines')} className="md:col-span-2">
              <ul className="flex flex-col gap-2 text-sm">
                {data.routines.map((routine) => (
                  <li key={routine.key} className="flex items-center justify-between gap-3">
                    <span className="text-content">{t(`aiCenter.routine.${routine.key}`)}</span>
                    <span className="text-content-tertiary text-xs">
                      {t(`aiCenter.schedule.${routine.schedule}`)}
                    </span>
                  </li>
                ))}
              </ul>
            </ChartCard>
          </section>

          <ModelRouting />

          <ChartCard title={t('aiCenter.tools')}>
            <ul className="divide-line-subtle flex flex-col divide-y">
              {data.tools.map((tool) => (
                <li
                  key={tool.name}
                  className="flex flex-wrap items-center justify-between gap-3 py-2.5"
                >
                  <span className="flex min-w-0 flex-col">
                    <code className="text-content text-sm">{tool.name}</code>
                    <span className="text-content-tertiary truncate text-xs">
                      {tool.description}
                    </span>
                  </span>
                  <span className="flex items-center gap-2 text-xs">
                    <span className="bg-surface-sunken text-content-secondary rounded-full px-2 py-0.5 font-medium">
                      {t(`aiCenter.risk.${tool.risk}`)}
                    </span>
                    <span className="ds-numeric text-content-secondary w-20 text-end">
                      {t('aiCenter.calls', { count: tool.calls_24h })}
                    </span>
                    <span
                      className={cn(
                        'ds-numeric w-20 text-end',
                        tool.refused_24h > 0 ? 'text-warning' : 'text-content-tertiary',
                      )}
                    >
                      {t('aiCenter.refused', { count: tool.refused_24h })}
                    </span>
                  </span>
                </li>
              ))}
            </ul>
          </ChartCard>

          <div className="grid gap-4 xl:grid-cols-2">
            <ChartCard title={t('aiCenter.providers')}>
              <ul className="flex flex-col gap-2 text-sm">
                {data.providers.map((p) => (
                  <li key={p.provider} className="flex items-center justify-between gap-3">
                    <span className="text-content">{p.label}</span>
                    <span className="flex gap-2 text-xs">
                      <span
                        className={cn(
                          'rounded-full px-2 py-0.5 font-medium',
                          SUPPORT_TONE[p.status_write],
                        )}
                      >
                        {t('aiCenter.pause')} · {t(`aiCenter.support.${p.status_write}`)}
                      </span>
                      <span
                        className={cn(
                          'rounded-full px-2 py-0.5 font-medium',
                          SUPPORT_TONE[p.budget_write],
                        )}
                      >
                        {t('aiCenter.budget')} · {t(`aiCenter.support.${p.budget_write}`)}
                      </span>
                    </span>
                  </li>
                ))}
              </ul>
            </ChartCard>

            <ChartCard title={t('aiCenter.mcp')}>
              <p className="bg-surface-sunken text-content rounded-2xl px-4 py-3 font-mono text-xs break-all">
                POST {data.mcp_endpoint}
              </p>
              <form
                className="flex gap-2"
                onSubmit={(event) => {
                  event.preventDefault();

                  if (name.trim() !== '') {
                    create.mutate(name.trim(), { onSuccess: (token) => setFresh(token) });
                    setName('');
                  }
                }}
              >
                <Input
                  value={name}
                  onChange={(event) => setName(event.target.value)}
                  placeholder={t('aiCenter.tokenName')}
                  aria-label={t('aiCenter.tokenName')}
                />
                <Button type="submit" disabled={create.isPending || name.trim() === ''}>
                  <KeyRound />
                  {t('aiCenter.newToken')}
                </Button>
              </form>
              {fresh === null ? null : (
                <Alert tone="warning">
                  <span className="flex w-full flex-col gap-2">
                    <span>{t('aiCenter.copyOnce')}</span>
                    <span className="flex items-center gap-2">
                      <code className="min-w-0 flex-1 truncate font-mono text-xs">{fresh}</code>
                      <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => void navigator.clipboard.writeText(fresh)}
                      >
                        <Copy />
                        {t('aiCenter.copy')}
                      </Button>
                    </span>
                  </span>
                </Alert>
              )}
              <ul className="flex flex-col gap-2 text-sm">
                {(tokens.data ?? []).map((token) => (
                  <li key={token.id} className="flex items-center justify-between gap-3">
                    <span className="text-content">{token.name}</span>
                    <span className="flex items-center gap-2">
                      <span className="text-content-tertiary text-xs">
                        {token.last_used_at === null
                          ? t('aiCenter.unused')
                          : formatRelativeTime(token.last_used_at, i18n.language)}
                      </span>
                      <Button
                        size="icon"
                        variant="ghost"
                        aria-label={t('aiCenter.revoke', { name: token.name })}
                        onClick={() => revoke.mutate(token.id)}
                      >
                        <Trash2 />
                      </Button>
                    </span>
                  </li>
                ))}
              </ul>
            </ChartCard>
          </div>
        </>
      )}
    </div>
  );
}
