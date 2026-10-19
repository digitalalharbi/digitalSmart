import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CheckCircle2 } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useApprovalQueueQuery } from '@/features/approvals';
import { AgentFindingDecision, useAgentFindings } from '@/features/agents';
import { useAttentionQuery } from '@/features/attention';
import { cn } from '@/shared/lib/cn';
import { formatNumber } from '@/shared/lib/format';
import { Skeleton } from '@/shared/ui/skeleton';
import { ApprovalDecision } from './ApprovalDecision';
import { AttentionDecision } from './AttentionDecision';
import { ACTION_TYPES, APPROVAL_TYPE, itemType, type ActionType } from './kinds';

const SEVERITY_RANK = { critical: 0, needs_attention: 1, watch: 2 } as const;

/**
 * Everything waiting on this person, as decisions.
 *
 * One queue instead of a queue per module: pending approvals first (they are already a yes or a no), then what the
 * system has found, worst first. The count and the kinds are on top as chips that filter the list, and nothing is
 * grouped under headings or explained in a paragraph — a card is the whole of it.
 */
export function ActionCenter({ limit }: { limit?: number }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { can } = useSession();
  const [only, setOnly] = useState<ActionType | null>(null);

  const mayDecide = can(PERMISSIONS.approvalsView);
  const approvals = useApprovalQueueQuery({}, mayDecide);
  const attention = useAttentionQuery([], 60, 'all');
  /*
   * The agents' cards are read on their own as well: they are newest, so inside a severity they sort last and the
   * general list's cap would cut them — and what the agents saw is half of what this screen is for.
   */
  const agentItems = useAttentionQuery(['agents'], 30, 'all');

  /* What an agent prepared comes first: it arrives with its figures and target, ready to decide. */
  const pending = useMemo(
    () =>
      [...(approvals.data?.pending ?? [])].sort(
        (a, b) => Number(b.origin === 'agent') - Number(a.origin === 'agent'),
      ),
    [approvals.data],
  );
  const found = useMemo(() => {
    const seen = new Set<string>();

    return [...(attention.data?.data ?? []), ...(agentItems.data?.data ?? [])]
      .filter((item) => itemType(item) !== null && !seen.has(item.id) && seen.add(item.id))
      .sort((a, b) => SEVERITY_RANK[a.severity] - SEVERITY_RANK[b.severity]);
  }, [attention.data, agentItems.data]);

  const counts = useMemo(() => {
    const out = new Map<ActionType, number>();

    for (const row of pending) {
      const type = APPROVAL_TYPE[row.kind];
      out.set(type, (out.get(type) ?? 0) + 1);
    }

    for (const item of found) {
      const type = itemType(item);

      if (type !== null) {
        out.set(type, (out.get(type) ?? 0) + 1);
      }
    }

    return out;
  }, [pending, found]);

  const total = [...counts.values()].reduce((sum, n) => sum + n, 0);

  /* An agent's card carries its picture — the ad, the two fortnights — which the queue item does not. */
  const hasAgentItems = found.some((item) => item.stream === 'agents');
  const findings = useAgentFindings(hasAgentItems);
  const byId = useMemo(
    () => new Map((findings.data ?? []).map((finding) => [`agent:${finding.id}`, finding])),
    [findings.data],
  );

  if ((mayDecide && approvals.isPending) || attention.isPending) {
    return (
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3" aria-busy="true">
        {Array.from({ length: 3 }, (_, i) => (
          <Skeleton key={i} className="h-56 rounded-3xl" />
        ))}
      </div>
    );
  }

  const shownApprovals = pending.filter((row) => only === null || APPROVAL_TYPE[row.kind] === only);
  const shownItems = found.filter((item) => only === null || itemType(item) === only);
  const cap = limit ?? Number.POSITIVE_INFINITY;

  return (
    <div className="flex flex-col gap-5">
      <div
        className="flex flex-wrap items-center gap-2"
        role="group"
        aria-label={t('actionCenter.filter')}
      >
        <button
          type="button"
          aria-pressed={only === null}
          onClick={() => setOnly(null)}
          className={cn(
            'rounded-full px-4 py-2 text-sm font-semibold',
            only === null
              ? 'bg-action text-action-contrast'
              : 'bg-surface text-content-secondary hover:bg-surface-hover',
          )}
        >
          {t('actionCenter.all', { count: total })}
        </button>
        {ACTION_TYPES.filter((type) => (counts.get(type) ?? 0) > 0).map((type) => (
          <button
            key={type}
            type="button"
            aria-pressed={only === type}
            onClick={() => setOnly(only === type ? null : type)}
            className={cn(
              'rounded-full px-4 py-2 text-sm font-medium',
              only === type
                ? 'bg-action text-action-contrast'
                : 'bg-surface text-content-secondary hover:bg-surface-hover',
            )}
          >
            <span className="ds-numeric me-1.5 font-semibold">
              {formatNumber(counts.get(type) ?? 0, locale)}
            </span>
            {t(`actionCenter.type.${type}`)}
          </button>
        ))}
      </div>

      {shownApprovals.length + shownItems.length === 0 ? (
        <div className="bg-surface flex flex-col items-center gap-3 rounded-3xl px-6 py-14 text-center shadow-xs">
          <CheckCircle2 aria-hidden className="text-success size-10" />
          <p className="text-content text-base font-semibold">{t('actionCenter.clear')}</p>
        </div>
      ) : (
        <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {shownApprovals.slice(0, cap).map((row) => (
            <li key={row.id} className="flex">
              <ApprovalDecision row={row} locale={locale} />
            </li>
          ))}
          {shownItems.slice(0, Math.max(0, cap - shownApprovals.length)).map((item) => (
            <li key={item.id} className="flex">
              {byId.has(item.id) ? (
                <AgentFindingDecision finding={byId.get(item.id)!} />
              ) : (
                <AttentionDecision item={item} />
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
