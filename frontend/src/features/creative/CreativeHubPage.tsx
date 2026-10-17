import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { Button } from '@/shared/ui/button';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useClientsQuery } from '@/features/clients';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { PageHeader } from '@/shared/ui/page-header';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { cn } from '@/shared/lib/cn';
import { CreativeDesk } from './CreativeDesk';
import { CreativeGallery, CreativeTable, CreativeThumb } from './CreativeViews';
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

  /* Searched, never listed: a hundred-client agency cannot be a row of buttons, and the 101st is not a typo. */
  const [client, setClient] = useState<{ id: string; label: string } | null>(null);
  const [clientTerm, setClientTerm] = useState('');
  const clientId = client?.id ?? null;
  const [selected, setSelected] = useState<string | null>(null);
  const [params, setParams] = useSearchParams();
  const requestedView = params.get('view');
  const view = requestedView === 'board' || requestedView === 'table' ? requestedView : 'gallery';

  const clients = useClientsQuery({ search: clientTerm, per_page: 8 });
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
          <EntityPicker
            label={t('creative.scope.client')}
            clearLabel={t('actions.clear')}
            value={client}
            onChange={(option) =>
              setClient(option === null ? null : { id: option.id, label: option.label })
            }
            onSearch={setClientTerm}
            options={(clients.data?.data ?? []).map((c) => ({ id: c.id, label: c.name }))}
            isLoading={clients.isFetching}
          />
        }
      />

      {board.isError ? <Alert tone="danger">{t('creative.loadFailed')}</Alert> : null}

      {board.isPending ? (
        <Skeleton className="h-32 w-full" />
      ) : board.data === undefined ? null : (
        <>
          <CreativeDesk board={board.data} />

          <div className="grid gap-6 xl:grid-cols-2">
            <WorkloadChart board={board.data} />
            <SpecCoverage specs={specs.data ?? []} isLoading={specs.isPending} />
          </div>

          <div className="flex gap-1" role="group" aria-label={t('creative.view.label')}>
            {(['gallery', 'board', 'table'] as const).map((key) => (
              <Button
                key={key}
                size="sm"
                variant={view === key ? 'secondary' : 'ghost'}
                aria-pressed={view === key}
                onClick={() => setParams(key === 'gallery' ? {} : { view: key }, { replace: true })}
              >
                {t(`creative.view.${key}`)}
              </Button>
            ))}
          </div>

          {view === 'gallery' ? (
            <CreativeGallery
              cards={board.data.columns.flatMap((column) => column.requests)}
              selected={selected}
              onSelect={setSelected}
            />
          ) : null}
          {view === 'table' ? (
            <CreativeTable
              cards={board.data.columns.flatMap((column) => column.requests)}
              selected={selected}
              onSelect={setSelected}
            />
          ) : null}
          {view === 'board' ? (
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
                            'bg-surface border-line-subtle w-full rounded-2xl border p-3 text-start shadow-xs',
                            'ease-standard transition-[border-color,box-shadow] duration-[var(--ds-duration-fast)]',
                            'hover:border-line-strong focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none',
                            selected === card.id && 'border-accent ring-accent/25 ring-1',
                          )}
                        >
                          <CreativeThumb card={card} className="mb-2 h-24" />
                          <span className="text-content block text-sm font-medium">
                            {card.title}
                          </span>
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
          ) : null}
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
