import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Bot, MessageCircle } from 'lucide-react';
import { AgentFindingDecision, AskAgents, useAgentFindings } from '@/features/agents';
import { cn } from '@/shared/lib/cn';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { ContextDrawer } from '@/shared/ui/context-drawer';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { useAgentsStatus, type AgentStatus } from './api';

const STATUS_TONE = {
  active: 'bg-success-surface text-success',
  failing: 'bg-danger-surface text-danger',
  never: 'bg-surface-sunken text-content-tertiary',
} as const;

/**
 * Agents — each one a worker with a job, not a chatbot: what it does, whether it is running well, its routines, what
 * it has waiting for a person, when it last ran and how often its runs succeed. Opening one shows its recent findings.
 * Asking an agent a question is there, and secondary.
 */
export function AgentsPage() {
  const { t, i18n } = useTranslation();
  const status = useAgentsStatus();
  const findings = useAgentFindings();
  const [open, setOpen] = useState<AgentStatus | null>(null);
  const [asking, setAsking] = useState(false);

  const mine = (agent: string) => (findings.data ?? []).filter((f) => f.agent === agent);

  return (
    <div className="ds-page">
      <PageHeader
        title={t('automation.agents')}
        actions={
          <Button variant="secondary" onClick={() => setAsking((v) => !v)}>
            <MessageCircle />
            {t('automation.askAgent')}
          </Button>
        }
      />
      {asking ? <AskAgents /> : null}
      {status.isError ? <Alert tone="danger">{t('automation.failed')}</Alert> : null}
      {status.isPending ? (
        <Skeleton className="h-64 rounded-3xl" />
      ) : (
        <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {(status.data?.agents ?? []).map((agent) => (
            <li key={agent.agent}>
              <button
                type="button"
                onClick={() => setOpen(agent)}
                className="bg-surface hover:bg-surface-hover flex h-full w-full flex-col gap-3 rounded-3xl p-5 text-start shadow-xs"
              >
                <span className="flex items-center justify-between gap-2">
                  <span className="flex items-center gap-2">
                    <span className="bg-accent-subtle text-content-brand grid size-9 place-items-center rounded-full">
                      <Bot aria-hidden className="size-4" />
                    </span>
                    <span className="text-content font-semibold">
                      {t(`automation.agent.${agent.agent}.name`)}
                    </span>
                  </span>
                  <span
                    className={cn(
                      'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                      STATUS_TONE[agent.status],
                    )}
                  >
                    {t(`automation.agentStatus.${agent.status}`)}
                  </span>
                </span>
                <span className="text-content-secondary text-sm">
                  {t(`automation.agent.${agent.agent}.role`)}
                </span>
                <dl className="grid grid-cols-3 gap-2">
                  <Stat
                    label={t('automation.openRecs')}
                    value={String(agent.open_recommendations)}
                  />
                  <Stat
                    label={t('automation.successRate')}
                    value={agent.success_rate === null ? '—' : `${String(agent.success_rate)}%`}
                  />
                  <Stat
                    label={t('automation.routinesCount')}
                    value={String(agent.routines.length)}
                  />
                </dl>
                <span className="text-content-tertiary text-xs">
                  {agent.last_run === null
                    ? t('automation.notRunYet')
                    : t('automation.lastRan', {
                        when: formatRelativeTime(agent.last_run, i18n.language),
                      })}
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}

      {open === null ? null : (
        <ContextDrawer
          open
          onOpenChange={(o) => !o && setOpen(null)}
          title={t(`automation.agent.${open.agent}.name`)}
          description={t(`automation.agent.${open.agent}.role`)}
        >
          <div className="flex flex-col gap-4">
            <section className="flex flex-col gap-2">
              <h3 className="text-content text-sm font-semibold">{t('automation.routinesTab')}</h3>
              <ul className="flex flex-wrap gap-1.5">
                {open.routines.map((r) => (
                  <li key={r} className="bg-surface-sunken rounded-full px-3 py-1 text-xs">
                    {t(`automation.routine.${r}`)}
                  </li>
                ))}
              </ul>
            </section>
            <section className="flex flex-col gap-2">
              <h3 className="text-content text-sm font-semibold">
                {t('automation.recentFindings')}
              </h3>
              {mine(open.agent).length === 0 ? (
                <p className="text-content-tertiary text-sm">{t('automation.noFindings')}</p>
              ) : (
                <ul className="flex flex-col gap-3">
                  {mine(open.agent)
                    .slice(0, 6)
                    .map((f) => (
                      <li key={f.id} className="flex">
                        <AgentFindingDecision finding={f} />
                      </li>
                    ))}
                </ul>
              )}
            </section>
          </div>
        </ContextDrawer>
      )}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="bg-surface-sunken flex flex-col rounded-xl p-2">
      <dt className="text-content-tertiary truncate text-xs">{label}</dt>
      <dd className="ds-numeric text-content text-lg font-semibold">{value}</dd>
    </div>
  );
}
