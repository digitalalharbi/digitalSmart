import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { TriangleAlert } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { ApiError } from '@/shared/api/client';
import { cn } from '@/shared/lib/cn';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import { ModulePanel } from './ModulePanel';
import {
  useEnablePortalMutation,
  usePortalQuery,
  usePortalVocabularyQuery,
  usePortalsQuery,
  useResumePortalMutation,
  useSetPortalModulesMutation,
  useSuspendPortalMutation,
} from './usePortal';
import type { PortalDetail, PortalModuleValue } from './types';

/** The saved set as a stable string, which is both the editor's seed and its remount key. */
function exposedKey(detail: PortalDetail): string {
  return detail.modules
    .filter((entry) => entry.is_exposed)
    .map((entry) => entry.module)
    .join(',');
}

/**
 * The agency's control over what each client can read.
 *
 * The list includes **every visible client, including the ones with no portal**, because the question an agency
 * asks before a quarterly review is which of them still cannot see anything — and a list built from portals could
 * never answer it.
 */
export function PortalPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { can } = useSession();

  const canView = can(PERMISSIONS.portalView);
  const canManage = can(PERMISSIONS.portalManage);

  const [selected, setSelected] = useState<string | null>(null);
  /*
   * The suspension form, held against the client it belongs to rather than as a bare flag. Switching clients then
   * closes it and discards its reason by construction — a flag plus an effect would do the same thing a render
   * later, and the render in between would show one client's reason under another client's name.
   */
  const [draft, setDraft] = useState<{ clientId: string; reason: string } | null>(null);

  const vocabulary = usePortalVocabularyQuery(canView);
  const portals = usePortalsQuery(canView);
  const portal = usePortalQuery(canView ? selected : null);

  const enable = useEnablePortalMutation();
  const suspend = useSuspendPortalMutation();
  const resume = useResumePortalMutation();
  const save = useSetPortalModulesMutation();

  const error = useMemo(() => {
    for (const candidate of [enable.error, suspend.error, resume.error, save.error]) {
      if (candidate instanceof ApiError) {
        return candidate.message;
      }
    }

    return null;
  }, [enable.error, suspend.error, resume.error, save.error]);

  if (!canView) {
    return (
      <EmptyState
        title={t('portal.denied.title')}
        level={1}
        description={t('portal.denied.body')}
      />
    );
  }

  const list = portals.data ?? [];
  const detail = portal.data;

  const moment = (value: string): string => new Date(value).toLocaleString(locale);

  return (
    <div className="flex flex-col gap-6">
      <header>
        <h1 className="text-content text-2xl font-semibold">{t('portal.title')}</h1>
        <p className="text-content-secondary mt-1 max-w-2xl text-sm">{t('portal.subtitle')}</p>
      </header>

      {error === null ? null : <Alert tone="danger">{error}</Alert>}
      {portals.isError ? <Alert tone="danger">{t('portal.loadFailed')}</Alert> : null}

      {portals.isPending ? (
        <Skeleton className="h-32 w-full" />
      ) : (
        <section
          aria-label={t('portal.clients')}
          className="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
        >
          {list.map((entry) => (
            <button
              key={entry.client_id}
              type="button"
              onClick={() => setSelected(entry.client_id)}
              aria-pressed={selected === entry.client_id}
              className={cn(
                'bg-surface border-line-subtle rounded-2xl border p-4 text-start shadow-xs',
                'ease-standard transition-[border-color,box-shadow] duration-[var(--ds-duration-fast)]',
                'hover:border-line-strong focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none',
                selected === entry.client_id && 'border-accent ring-accent/25 ring-1',
              )}
            >
              <span className="text-content block text-sm font-medium">{entry.client_name}</span>
              <span className="mt-2 flex flex-wrap items-center gap-1.5">
                <Badge
                  tone={
                    entry.state === 'active'
                      ? 'accent'
                      : entry.state === 'suspended'
                        ? 'danger'
                        : 'neutral'
                  }
                >
                  {entry.state_label}
                </Badge>
                <Badge tone="neutral">
                  {entry.exposed_count === 0
                    ? t('portal.card.nothing')
                    : t('portal.card.exposed', { count: entry.exposed_count })}
                </Badge>
                {/*
                  Exposed with nothing behind it. Its own badge rather than something a reader works out by
                  opening each client, because it is the figure that embarrasses somebody.
                */}
                {entry.empty_count > 0 ? (
                  <Badge tone="warning">
                    {t('portal.card.empty', { count: entry.empty_count })}
                  </Badge>
                ) : null}
              </span>
            </button>
          ))}
        </section>
      )}

      {selected === null ? (
        <EmptyState title={t('portal.pick.title')} description={t('portal.pick.body')} />
      ) : portal.isPending ? (
        <Skeleton className="h-64 w-full" />
      ) : detail === undefined ? null : (
        <section
          aria-label={detail.client_name}
          className="bg-surface border-line-subtle flex flex-col gap-5 rounded-2xl border p-5"
        >
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 className="text-content text-lg font-semibold">{detail.client_name}</h2>
              <p className="text-content-secondary mt-1 text-sm">
                {detail.state === 'not_configured'
                  ? t('portal.detail.notConfigured')
                  : detail.state === 'suspended' && detail.suspended_at !== null
                    ? t('portal.detail.suspended', {
                        at: moment(detail.suspended_at),
                        who: detail.suspended_by_name ?? '—',
                      })
                    : detail.enabled_at === null
                      ? detail.state_label
                      : detail.enabled_by_name === null
                        ? t('portal.detail.enabledUnknown', { at: moment(detail.enabled_at) })
                        : t('portal.detail.enabled', {
                            at: moment(detail.enabled_at),
                            who: detail.enabled_by_name,
                          })}
              </p>
            </div>

            {!canManage ? null : detail.state === 'not_configured' ? (
              <Button onClick={() => enable.mutate(detail.client_id)} disabled={enable.isPending}>
                {t('portal.detail.enable')}
              </Button>
            ) : detail.state === 'suspended' ? (
              <Button onClick={() => resume.mutate(detail.client_id)} disabled={resume.isPending}>
                {t('portal.detail.resume')}
              </Button>
            ) : draft?.clientId === detail.client_id ? null : (
              <Button
                variant="ghost"
                onClick={() => setDraft({ clientId: detail.client_id, reason: '' })}
              >
                {t('portal.detail.suspend')}
              </Button>
            )}
          </div>

          {detail.state === 'not_configured' ? (
            <p className="text-content-tertiary text-sm">{t('portal.detail.enableHint')}</p>
          ) : null}

          {detail.suspended_reason === null ? null : (
            <Alert tone="warning" className="flex flex-col gap-1">
              <span>{t('portal.detail.reason', { reason: detail.suspended_reason })}</span>
              {/*
                Said explicitly on the agency's own screen. The client is told the portal is off and to make
                contact — a commercial position on a client's portal page is that conversation in the worst
                possible place.
              */}
              <span className="text-xs">{t('portal.detail.reasonInternal')}</span>
            </Alert>
          )}

          {draft?.clientId !== detail.client_id ? null : (
            <form
              className="flex flex-wrap items-end gap-2"
              onSubmit={(event) => {
                event.preventDefault();

                if (draft.reason.trim() === '') {
                  return;
                }

                suspend.mutate(
                  { clientId: detail.client_id, reason: draft.reason.trim() },
                  { onSuccess: () => setDraft(null) },
                );
              }}
            >
              <Field
                label={t('portal.detail.suspendReason')}
                hint={t('portal.detail.suspendHint')}
                className="min-w-64 flex-1"
                required
              >
                {(props) => (
                  <Input
                    {...props}
                    value={draft.reason}
                    onChange={(event) =>
                      setDraft({ clientId: detail.client_id, reason: event.target.value })
                    }
                    required
                  />
                )}
              </Field>
              <Button type="submit" disabled={draft.reason.trim() === '' || suspend.isPending}>
                {t('portal.detail.confirmSuspend')}
              </Button>
            </form>
          )}

          {detail.state === 'suspended' ? (
            <p className="text-content-tertiary text-sm">{t('portal.detail.resumeHint')}</p>
          ) : null}

          {detail.empty_count > 0 ? (
            <Alert tone="warning" className="flex items-start gap-2">
              <TriangleAlert aria-hidden className="mt-0.5 size-4 shrink-0" />
              <span>{t('portal.modules.emptyWarning', { count: detail.empty_count })}</span>
            </Alert>
          ) : null}

          <ModulePanel
            /*
             * Keyed on the client and the saved set, so the editor re-seeds itself by remounting rather than
             * through an effect. See the note in `ModulePanel`.
             */
            key={`${detail.client_id}:${exposedKey(detail)}`}
            detail={detail}
            exposed={exposedKey(detail)}
            vocabulary={vocabulary.data}
            canManage={canManage && detail.state === 'active'}
            isSaving={save.isPending}
            onSave={(modules: PortalModuleValue[]) =>
              save.mutate({ clientId: detail.client_id, modules })
            }
          />
        </section>
      )}
    </div>
  );
}
