import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { X } from 'lucide-react';
import { api } from '@/shared/api/client';
import { Button } from '@/shared/ui/button';
import { Input } from '@/shared/ui/input';

type Action = 'request_pause' | 'request_resume' | 'request_budget_change' | 'create_task';
type Result = {
  done: number;
  refused: number;
  results: Array<{ campaign_id: string; ok: boolean; code: string | null }>;
};

/**
 * One action for the campaigns ticked on this page.
 *
 * Every campaign goes through the same request it would go through alone — a pause or a budget change waits for
 * approval, keeps the project's limits and never stacks — and the result says how many went through and why the
 * rest did not.
 */
export function BulkBar({ ids, onClear }: { ids: string[]; onClear: () => void }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const [action, setAction] = useState<Action>('request_pause');
  const [pct, setPct] = useState(-10);
  const [reason, setReason] = useState('');
  const run = useMutation({
    mutationFn: async (): Promise<Result> =>
      (
        await api.post<{ data: Result }>('/campaigns/bulk', {
          campaign_ids: ids,
          action,
          reason: reason.trim(),
          ...(action === 'request_budget_change' ? { change_pct: pct } : {}),
        })
      ).data.data,
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ['approvals'] });
      void client.invalidateQueries({ queryKey: ['attention'] });
    },
  });

  const refusedCodes = [
    ...new Set((run.data?.results ?? []).filter((r) => !r.ok).map((r) => r.code ?? 'failed')),
  ];

  return (
    <div
      role="region"
      aria-label={t('bulk.title')}
      className="bg-action text-action-contrast sticky bottom-4 z-20 flex flex-col gap-3 rounded-3xl p-4 shadow-lg sm:flex-row sm:items-center"
    >
      <span className="ds-numeric shrink-0 text-sm font-semibold">
        {t('bulk.selected', { count: ids.length })}
      </span>
      <select
        aria-label={t('bulk.action')}
        value={action}
        onChange={(e) => setAction(e.target.value as Action)}
        className="text-content h-10 rounded-full bg-white px-3 text-sm"
      >
        {(['request_pause', 'request_resume', 'request_budget_change', 'create_task'] as const).map(
          (a) => (
            <option key={a} value={a}>
              {t(`bulk.actions.${a}`)}
            </option>
          ),
        )}
      </select>
      {action === 'request_budget_change' ? (
        <select
          aria-label={t('bulk.change')}
          value={pct}
          onChange={(e) => setPct(Number(e.target.value))}
          className="text-content h-10 rounded-full bg-white px-3 text-sm"
        >
          {[-20, -15, -10, -5, 5, 10, 15, 20].map((p) => (
            <option key={p} value={p}>
              {p > 0 ? `+${String(p)}` : String(p)}%
            </option>
          ))}
        </select>
      ) : null}
      <Input
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        placeholder={t('bulk.reason')}
        aria-label={t('bulk.reason')}
        className="text-content min-w-0 flex-1 bg-white"
        dir="auto"
      />
      <Button
        variant="secondary"
        disabled={reason.trim().length < 3 || run.isPending}
        onClick={() => run.mutate()}
      >
        {t('bulk.go')}
      </Button>
      {run.data === undefined ? null : (
        <span role="status" className="text-xs">
          {t('bulk.result', { done: run.data.done, refused: run.data.refused })}
          {refusedCodes.length > 0 ? ` (${refusedCodes.join(', ')})` : ''}
        </span>
      )}
      <button
        type="button"
        onClick={onClear}
        aria-label={t('bulk.clear')}
        className="grid size-8 shrink-0 place-items-center rounded-full hover:bg-white/10"
      >
        <X aria-hidden className="size-4" />
      </button>
    </div>
  );
}
