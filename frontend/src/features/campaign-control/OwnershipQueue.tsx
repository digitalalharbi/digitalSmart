import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { useBulkAssign, useOwnershipQueue, type OwnershipRow } from './useOwnershipQueue';

/** The projects every selected campaign may legally join — the intersection of their candidates. */
function commonCandidates(rows: OwnershipRow[]): Array<{ id: string; name: string }> {
  const [first, ...rest] = rows;

  if (first === undefined) {
    return [];
  }

  return first.candidate_projects.filter((candidate) =>
    rest.every((row) => row.candidate_projects.some((c) => c.id === candidate.id)),
  );
}

/**
 * Campaigns that belong to no project yet, and a way to place many of them at once.
 *
 * Shown only when there is something to place. The target list is never the whole agency's projects: it is the
 * projects the selected campaigns' accounts are *already bound to*, which is the only set the server will accept
 * — so the picker cannot offer a choice that is certain to be refused, and a campaign cannot be used to grant a
 * project sight of an account it has no binding to.
 */
export function OwnershipQueue() {
  const { t } = useTranslation();
  const queue = useOwnershipQueue();
  const assign = useBulkAssign();
  const [selected, setSelected] = useState<string[]>([]);
  const [projectId, setProjectId] = useState('');

  const rows = queue.data?.data ?? [];
  const counts = queue.data?.meta.counts ?? {};
  const needing = (counts['ambiguous'] ?? 0) + (counts['unbound'] ?? 0);

  if (needing === 0 && assign.data === undefined) {
    return null;
  }

  const chosen = rows.filter((row) => selected.includes(row.id));
  const options = commonCandidates(chosen);
  const target = options.some((o) => o.id === projectId) ? projectId : '';

  return (
    <details className="bg-surface rounded-3xl p-5 shadow-xs">
      <summary className="text-content cursor-pointer text-sm font-semibold">
        {t('campaignControl.ownership.title', { count: needing })}
      </summary>
      <p className="text-content-tertiary mt-1 text-xs">{t('campaignControl.ownership.body')}</p>

      <ul className="mt-3 flex flex-col gap-1.5">
        {rows.map((row) => {
          const canPlace = row.candidate_projects.length > 0;

          return (
            <li key={row.id} className="flex items-center gap-3 text-sm">
              <input
                type="checkbox"
                id={`own-${row.id}`}
                disabled={!canPlace}
                checked={selected.includes(row.id)}
                onChange={(event) =>
                  setSelected((current) =>
                    event.target.checked
                      ? [...current, row.id]
                      : current.filter((id) => id !== row.id),
                  )
                }
              />
              <label htmlFor={`own-${row.id}`} className="flex min-w-0 flex-1 flex-col">
                <span className="text-content truncate">{row.name}</span>
                <span className="text-content-tertiary truncate text-xs">
                  {row.external_account.name} ·{' '}
                  {canPlace ? row.ownership_label : t('campaignControl.ownership.bindFirst')}
                </span>
              </label>
            </li>
          );
        })}
      </ul>

      <div className="mt-3 flex flex-wrap items-end gap-3">
        <label className="flex flex-col gap-1 text-xs font-medium">
          <span className="text-content-secondary">{t('campaignControl.ownership.target')}</span>
          <select
            className="border-line bg-surface h-9 rounded-md border px-2 text-sm"
            value={target}
            disabled={options.length === 0}
            onChange={(event) => setProjectId(event.target.value)}
          >
            <option value="">{t('campaignControl.ownership.choose')}</option>
            {options.map((option) => (
              <option key={option.id} value={option.id}>
                {option.name}
              </option>
            ))}
          </select>
        </label>
        <Button
          size="sm"
          disabled={target === '' || chosen.length === 0 || assign.isPending}
          onClick={() =>
            assign.mutate(
              { campaignIds: chosen.map((row) => row.id), projectId: target },
              { onSuccess: () => setSelected([]) },
            )
          }
        >
          {t('campaignControl.ownership.assign', { count: chosen.length })}
        </Button>
        {chosen.length > 1 && options.length === 0 ? (
          <p className="text-content-tertiary text-xs">{t('campaignControl.ownership.noCommon')}</p>
        ) : null}
      </div>

      {assign.data === undefined ? null : (
        <div className="mt-3" aria-live="polite">
          <Alert tone={assign.data.refused.length === 0 ? 'info' : 'warning'}>
            {t('campaignControl.ownership.result', {
              assigned: assign.data.assigned.length,
              refused: assign.data.refused.length,
            })}
          </Alert>
        </div>
      )}
    </details>
  );
}
