import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useClientsQuery } from '@/features/clients';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Card, CardContent } from '@/shared/ui/card';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { PageHeader } from '@/shared/ui/page-header';
import { cn } from '@/shared/lib/cn';
import { RequestDetail } from './RequestDetail';
import { SpecCoverage } from './SpecCoverage';
import { WorkloadChart } from './WorkloadChart';
import {
  useAssignCreativeOwnerMutation,
  useCommentMutation,
  useCreativeBoardQuery,
  useCreativeRequestQuery,
  useCreativeVocabularyQuery,
  useCreativeOwnersQuery,
  useDecideMutation,
  useMoveRequestMutation,
  useRecordedSpecsQuery,
} from './useCreative';
import type { CommentVisibilityValue } from './types';

/**
 * The Creative Hub: a board, the work on it, and what a specification makes of each file.
 *
 * The board is read-only here on purpose. Moving work is a decision with consequences — an approval needs a
 * version and a decider, a cancellation needs a reason — so the moves live beside the work they describe rather
 * than behind a drag. A Kanban that lets a column change undo an approval is a Kanban that produces an approval
 * trail nobody can rely on.
 */
export function CreativeHubPage() {
  const { t } = useTranslation();
  const { can } = useSession();

  const canView = can(PERMISSIONS.creativeView);
  const canApprove = can(PERMISSIONS.creativeApprove);
  const canManage = can(PERMISSIONS.creativeManage);

  const [clientId, setClientId] = useState<string | null>(null);
  const [selected, setSelected] = useState<string | null>(null);

  const clients = useClientsQuery({ page: 1, per_page: 100, sort: 'name', direction: 'asc' });
  const vocabulary = useCreativeVocabularyQuery(canView);
  const board = useCreativeBoardQuery(clientId, canView);
  const specs = useRecordedSpecsQuery(canView);
  const request = useCreativeRequestQuery(selected);

  const decide = useDecideMutation();
  const comment = useCommentMutation();
  const move = useMoveRequestMutation();
  const assign = useAssignCreativeOwnerMutation();
  /* Who the open brief can be handed to, from the server — see `OwnerDirectory`. */
  const owners = useCreativeOwnersQuery(selected, canManage);

  const error = useMemo(() => {
    for (const mutation of [decide, comment, move, assign]) {
      if (mutation.error instanceof ApiError && mutation.error.message !== '') {
        return mutation.error.message;
      }
    }

    return null;
  }, [decide, comment, move, assign]);

  if (!canView) {
    return (
      <EmptyState title={t('creative.denied.title')} description={t('creative.denied.body')} />
    );
  }

  return (
    <div className="ds-page">
      <PageHeader
        title={t('creative.title')}
        description={t('creative.subtitle')}
        filters={
          <ChoiceGroup label={t('creative.scope.client')}>
            <div className="flex flex-wrap gap-2">
              <Choice isSelected={clientId === null} onSelect={() => setClientId(null)}>
                {t('creative.scope.allClients')}
              </Choice>
              {(clients.data?.data ?? []).map((client) => (
                <Choice
                  key={client.id}
                  isSelected={clientId === client.id}
                  onSelect={() => setClientId(client.id)}
                >
                  {client.name}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>
        }
      />

      {board.isError ? <Alert tone="danger">{t('creative.loadFailed')}</Alert> : null}

      {board.isPending ? (
        <Skeleton className="h-32 w-full" />
      ) : board.data === undefined ? null : (
        <>
          <section aria-label={t('creative.counts')} className="grid gap-4 sm:grid-cols-3">
            {[
              [t('creative.count.open'), board.data.open, 'neutral'],
              [t('creative.count.waiting'), board.data.waiting_on_review, 'accent'],
              // Overdue only counts open work: a finished request is not late.
              [t('creative.count.overdue'), board.data.overdue, 'danger'],
            ].map(([label, value, tone]) => (
              <Card key={String(label)}>
                <CardContent className="flex flex-col gap-1 pt-5">
                  <span className="text-content-tertiary text-xs font-medium tracking-wide uppercase">
                    {String(label)}
                  </span>
                  <span className="text-content text-2xl font-semibold">{Number(value)}</span>
                  {Number(value) > 0 && tone === 'danger' ? (
                    <Badge tone="danger">{t('creative.count.needsAttention')}</Badge>
                  ) : null}
                </CardContent>
              </Card>
            ))}
          </section>

          <div className="grid gap-6 xl:grid-cols-2">
            <WorkloadChart board={board.data} />
            <SpecCoverage specs={specs.data ?? []} isLoading={specs.isPending} />
          </div>

          <section
            aria-label={t('creative.board')}
            tabIndex={0}
            className="flex gap-4 overflow-x-auto pb-2"
          >
            {board.data.columns.map((column) => (
              <div key={column.state} className="flex w-64 shrink-0 flex-col gap-2">
                <h2 className="text-content-secondary flex items-center justify-between text-xs font-medium tracking-wide uppercase">
                  {column.label}
                  <span className="text-content-tertiary tabular-nums">
                    {column.requests.length}
                  </span>
                </h2>

                <ul className="flex flex-col gap-2">
                  {column.requests.map((card) => (
                    <li key={card.id}>
                      <button
                        type="button"
                        onClick={() => setSelected(card.id)}
                        aria-pressed={selected === card.id}
                        className={cn(
                          'bg-surface border-line-subtle w-full rounded-xl border p-3 text-start shadow-xs',
                          'ease-standard transition-[border-color,box-shadow] duration-[var(--ds-duration-fast)]',
                          'hover:border-line-strong focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none',
                          selected === card.id && 'border-accent ring-accent/25 ring-1',
                        )}
                      >
                        <span className="text-content block text-sm font-medium">{card.title}</span>
                        <span className="text-content-tertiary mt-1 block text-xs">
                          {card.client_name} · {card.kind_label}
                        </span>
                        <span className="mt-2 flex flex-wrap items-center gap-1.5">
                          {card.version_count > 0 ? (
                            <Badge tone="neutral">
                              {t('creative.card.versions', { count: card.version_count })}
                            </Badge>
                          ) : null}
                          {card.is_overdue ? (
                            <Badge tone="danger">{t('creative.card.overdue')}</Badge>
                          ) : card.days_until_due === null ? null : (
                            <Badge tone="neutral">
                              {t('creative.card.due', { days: card.days_until_due })}
                            </Badge>
                          )}
                          {/*
                            A placement nobody has decided is why most spec checks honestly report checking
                            nothing, so it is said on the card rather than discovered in the detail.
                          */}
                          {card.placement === null ? (
                            <Badge tone="warning">{t('creative.card.noPlacement')}</Badge>
                          ) : null}
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </section>
        </>
      )}

      <RequestDetail
        request={selected === null ? undefined : request.data}
        vocabulary={vocabulary.data}
        isLoading={selected !== null && request.isPending}
        canApprove={canApprove}
        onDecide={(versionId, outcome, reason) =>
          decide.mutate({ versionId, outcome, ...(reason === undefined ? {} : { reason }) })
        }
        onComment={(body, visibility: CommentVisibilityValue) => {
          if (selected !== null) {
            comment.mutate({ requestId: selected, body, visibility });
          }
        }}
        canManage={canManage}
        onMove={(state, reason) => {
          if (selected !== null) {
            move.mutate({
              requestId: selected,
              state,
              ...(reason === undefined ? {} : { reason }),
            });
          }
        }}
        owners={selected === null ? undefined : owners.data}
        onAssign={(ownerId) => {
          if (selected !== null) {
            assign.mutate({ requestId: selected, ownerId });
          }
        }}
        error={error}
      />
    </div>
  );
}
