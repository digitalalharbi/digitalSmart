import { useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { providerName } from '@/shared/lib/providers';
import { Alert } from '@/shared/ui/alert';
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
import { useActionHistory } from './api';
import { actionText, when } from './ruleText';
import type { Execution, ExecutionResult } from './types';

const RESULT_TONE: Record<ExecutionResult, string> = {
  completed: 'bg-success-surface text-success',
  waiting_approval: 'bg-accent-subtle text-content-brand',
  no_change: 'bg-surface-sunken text-content-secondary',
  failed: 'bg-danger-surface text-danger',
  unsupported: 'bg-warning-surface text-warning',
};

/** One value before or after, the way a person reads it: a budget with its currency, a status word, a figure. */
function valueText(t: (k: string) => string, row: Execution, side: 'before' | 'after'): string {
  const v = row[side];

  if (v['status'] !== undefined && side === 'after')
    return t(`automation.status.${String(v['status'])}`);
  if (v['daily_budget'] !== undefined && v['daily_budget'] !== null)
    return `${String(v['daily_budget'])} ${String(row.before['currency'] ?? '')}`.trim();
  if (side === 'before' && v['status'] !== undefined)
    return t(`automation.status.${String(v['status'])}`);

  return '—';
}

/**
 * Action History — every action a rule took, was refused, or found unnecessary: when, which rule, whose campaign on
 * which platform, the action, the value before and after, and the result. A proposal waiting for approval links to it.
 */
export function ActionHistoryPage() {
  const { t, i18n } = useTranslation();
  const [params] = useSearchParams();
  const [days, setDays] = useState(30);
  const [result, setResult] = useState<string>('all');
  const history = useActionHistory({
    days,
    rule_id: params.get('rule'),
    result: result === 'all' ? null : result,
  });

  return (
    <div className="ds-page">
      <PageHeader title={t('automation.history')} />
      <div className="flex flex-wrap gap-2">
        <select
          aria-label={t('automation.filter.period')}
          value={days}
          onChange={(e) => setDays(Number(e.target.value))}
          className="bg-surface text-content-secondary h-10 rounded-full px-3 text-sm shadow-xs"
        >
          {[1, 7, 30, 90].map((d) => (
            <option key={d} value={d}>
              {t('automation.lastDays', { count: d })}
            </option>
          ))}
        </select>
        <select
          aria-label={t('automation.col.lastResult')}
          value={result}
          onChange={(e) => setResult(e.target.value)}
          className="bg-surface text-content-secondary h-10 rounded-full px-3 text-sm shadow-xs"
        >
          <option value="all">{t('automation.filter.anyResult')}</option>
          {(['completed', 'waiting_approval', 'no_change', 'failed', 'unsupported'] as const).map(
            (r) => (
              <option key={r} value={r}>
                {t(`automation.result.${r}`)}
              </option>
            ),
          )}
        </select>
      </div>

      {history.isError ? <Alert tone="danger">{t('automation.failed')}</Alert> : null}
      {history.isPending ? (
        <Skeleton className="h-48 rounded-3xl" />
      ) : (history.data ?? []).length === 0 ? (
        <p className="text-content-tertiary bg-surface rounded-2xl p-6 text-sm">
          {t('automation.noHistory')}
        </p>
      ) : (
        <DataTable label={t('automation.history')} minWidth="68rem">
          <TableHead>
            <TableRow>
              {(
                [
                  'time',
                  'rule',
                  'client',
                  'project',
                  'platform',
                  'object',
                  'action',
                  'before',
                  'after',
                  'result',
                ] as const
              ).map((c) => (
                <TableHeader key={c}>{t(`automation.hcol.${c}`)}</TableHeader>
              ))}
            </TableRow>
          </TableHead>
          <TableBody>
            {(history.data ?? []).map((row) => (
              <TableRow key={row.id}>
                <TableCell>
                  <span className="text-content-tertiary text-xs">
                    {when(row.at, i18n.language)}
                  </span>
                </TableCell>
                <TableCell>
                  <span className="text-content text-sm" dir="auto">
                    {row.rule.name}
                  </span>
                </TableCell>
                <TableCell>
                  <span className="text-content-secondary text-xs" dir="auto">
                    {row.client?.name ?? '—'}
                  </span>
                </TableCell>
                <TableCell>
                  <span className="text-content-secondary text-xs" dir="auto">
                    {row.project?.name ?? '—'}
                  </span>
                </TableCell>
                <TableCell>
                  <span className="text-content-secondary text-xs">
                    {row.provider === null ? '—' : providerName(row.provider)}
                  </span>
                </TableCell>
                <TableCell>
                  {row.object.campaign_id === null ? (
                    <span className="text-sm" dir="auto">
                      {row.object.label}
                    </span>
                  ) : (
                    <Link
                      to={`/campaigns/${row.object.campaign_id}`}
                      className="text-content text-sm hover:underline"
                      dir="auto"
                    >
                      {row.object.label}
                    </Link>
                  )}
                </TableCell>
                <TableCell>
                  <span className="text-sm">{actionText(t, row.action, null)}</span>
                </TableCell>
                <TableCell>
                  <span className="ds-numeric text-content-secondary text-xs">
                    {valueText(t, row, 'before')}
                  </span>
                </TableCell>
                <TableCell>
                  <span className="ds-numeric text-content text-xs font-semibold">
                    {valueText(t, row, 'after')}
                  </span>
                </TableCell>
                <TableCell>
                  <span className="flex flex-col gap-0.5">
                    <span
                      className={cn(
                        'w-fit rounded-full px-2.5 py-0.5 text-xs font-semibold',
                        RESULT_TONE[row.result],
                      )}
                    >
                      {t(`automation.result.${row.result}`)}
                    </span>
                    {row.reason_code === null || row.reason_code === 'NOTIFIED' ? null : (
                      <span className="text-content-tertiary text-xs">
                        {t(`automation.reason.${row.reason_code}`, {
                          defaultValue: row.reason_code,
                        })}
                      </span>
                    )}
                    {row.approval_id === null ? null : (
                      <Link
                        to={`/approvals?open=${row.approval_id}`}
                        className="text-content-brand text-xs underline"
                      >
                        {t('automation.openApproval')}
                      </Link>
                    )}
                  </span>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </DataTable>
      )}
    </div>
  );
}
