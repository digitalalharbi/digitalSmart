import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Copy, LayoutGrid, Pencil, Plus, Save, Trash2, X } from 'lucide-react';
import { FilterBar, useMetricCatalogue } from '@/features/analytics';
import { PERMISSIONS, useSession } from '@/features/auth';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import { PageHeader } from '@/shared/ui/page-header';
import { cn } from '@/shared/lib/cn';
import { DashboardCanvas } from './DashboardCanvas';
import { WidgetEditor } from './WidgetEditor';
import { findSlot, move, reflow, resize, toDraft } from './layout';
import {
  useDashboardQuery,
  useDashboardsQuery,
  useDeleteDashboard,
  useDuplicateDashboard,
  useSaveDashboard,
} from './useDashboards';
import type { DashboardDraft, DashboardPayload, VisibilityValue, WidgetDraft } from './types';

const VISIBILITIES: readonly VisibilityValue[] = ['private', 'workspace'];

/** A new dashboard starts with something on it, because an empty canvas explains nothing. */
function startingDraft(name: string): DashboardDraft {
  return {
    name,
    description: null,
    visibility: 'private',
    project_id: null,
    filters: { preset: '28d', compare: 'previous_period' },
    is_default: false,
    widgets: reflow([
      {
        key: 'start-spend',
        title: null,
        type: 'scorecard',
        metrics: ['spend'],
        dimension: null,
        filter_overrides: null,
        column: 0,
        row: 0,
        width: 3,
        height: 1,
        options: {},
      },
      {
        key: 'start-roas',
        title: null,
        type: 'scorecard',
        metrics: ['roas'],
        dimension: null,
        filter_overrides: null,
        column: 3,
        row: 0,
        width: 3,
        height: 1,
        options: {},
      },
      {
        key: 'start-trend',
        title: null,
        type: 'line',
        metrics: ['spend', 'roas'],
        dimension: null,
        filter_overrides: null,
        column: 6,
        row: 0,
        width: 6,
        height: 2,
        options: {},
      },
      {
        key: 'start-campaigns',
        title: null,
        type: 'bar',
        metrics: ['spend'],
        dimension: 'campaign',
        filter_overrides: null,
        column: 0,
        row: 1,
        width: 6,
        height: 2,
        options: {},
      },
    ]),
  };
}

/**
 * The dashboard builder.
 *
 * Editing is explicit: a reader opens a dashboard and reads it, and only enters edit
 * mode deliberately. Saving sends the whole canvas, because what a reader sees is
 * what the dashboard is — and the server replaces the widget set for the same reason.
 *
 * The draft lives in local state until saved, so re-flowing a layout costs no
 * requests and an abandoned edit changes nothing for anyone else looking at the
 * shared dashboard.
 */
export function DashboardsPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const canBuild = can(PERMISSIONS.savedViewsManage);
  const canRead = can(PERMISSIONS.analyticsView);

  const list = useDashboardsQuery(canRead);

  /** What the reader explicitly picked. Null means they have not picked anything. */
  const [chosenId, setChosenId] = useState<string | null>(null);
  /** A draft for a dashboard that does not exist yet. */
  const [isCreating, setCreating] = useState(false);
  const [draft, setDraft] = useState<DashboardDraft | null>(null);
  const [editingWidget, setEditingWidget] = useState<number | 'new' | null>(null);
  const [saveError, setSaveError] = useState<string | null>(null);

  const save = useSaveDashboard();
  const remove = useDeleteDashboard();
  const duplicate = useDuplicateDashboard();
  // Warms the metric catalogue so the editor's choices are ready when it opens.
  useMetricCatalogue(canRead);

  /**
   * Where the reader lands: their default, or the first dashboard they can see.
   *
   * Derived rather than synced into state by an effect. The effect version worked
   * until it did not: it could supply a selection while a new draft was open, and
   * saving then wrote over whichever dashboard had been selected instead of creating
   * one. Deriving it makes that impossible rather than merely fixed.
   */
  const landingId = useMemo(
    () => (list.data?.find((dashboard) => dashboard.is_default) ?? list.data?.[0])?.id ?? null,
    [list.data],
  );

  // A new dashboard has nothing to load; otherwise an explicit choice wins.
  const selectedId = isCreating ? null : (chosenId ?? landingId);
  const detail = useDashboardQuery(selectedId);

  /*
   * Narrowed against null as well as undefined: a query can settle with null, and
   * reading `.description` off it crashes the page rather than showing an empty state.
   */
  const dashboard = detail.data ?? null;
  const isEditing = draft !== null;

  const startEditing = (source: DashboardPayload) => {
    setSaveError(null);
    setCreating(false);
    setChosenId(source.id);
    setDraft({
      name: source.name,
      description: source.description,
      visibility: source.visibility,
      project_id: source.project_id,
      filters: source.filters,
      is_default: source.is_default,
      widgets: source.widgets.map(toDraft),
    });
  };

  const startNew = () => {
    setSaveError(null);
    setCreating(true);
    setDraft(startingDraft(t('dashboards.newName')));
  };

  const discard = () => {
    setDraft(null);
    setCreating(false);
    setEditingWidget(null);
    setSaveError(null);
  };

  const commit = async () => {
    if (draft === null) {
      return;
    }

    setSaveError(null);

    try {
      // Null when creating, which is what tells the API to insert rather than replace.
      const saved = await save.mutateAsync({ id: isCreating ? null : selectedId, draft });

      if (saved !== undefined) {
        setChosenId(saved.id);
      }

      setCreating(false);
      setDraft(null);
      setEditingWidget(null);
    } catch (error) {
      /*
       * The server refuses layouts the editor cannot catch — an overlap introduced by
       * a hand-edited request, a chart type that cannot show a dimension. Its message
       * names what is wrong and what would fix it, so it is shown rather than replaced
       * with a generic failure.
       */
      setSaveError(error instanceof ApiError ? error.message : t('dashboards.saveFailed'));
    }
  };

  const applyWidget = (widget: WidgetDraft) => {
    setDraft((current) => {
      if (current === null) {
        return current;
      }

      if (editingWidget === 'new') {
        const slot = findSlot(current.widgets, widget.width);

        return {
          ...current,
          widgets: reflow([...current.widgets, { ...widget, ...slot }]),
        };
      }

      if (typeof editingWidget !== 'number') {
        return current;
      }

      return {
        ...current,
        widgets: reflow(
          current.widgets.map((existing, index) => (index === editingWidget ? widget : existing)),
        ),
      };
    });

    setEditingWidget(null);
  };

  if (!canRead) {
    return (
      <div className="flex flex-col gap-6">
        <Heading />
        <Alert tone="warning">{t('analytics.forbidden')}</Alert>
      </div>
    );
  }

  if (list.isError) {
    const forbidden = list.error instanceof ApiError && list.error.status === 403;

    return (
      <div className="flex flex-col gap-6">
        <Heading />
        <Alert tone={forbidden ? 'warning' : 'danger'}>
          {forbidden ? t('analytics.forbidden') : t('dashboards.loadFailed')}
        </Alert>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <Heading />
        {canBuild && !isEditing && (
          <Button onClick={startNew}>
            <Plus aria-hidden className="size-4" />
            {t('dashboards.create')}
          </Button>
        )}
      </header>

      {/* The picker */}
      {list.isPending ? (
        <Skeleton className="h-10 w-full max-w-xl" />
      ) : (
        list.data !== undefined &&
        list.data.length > 0 && (
          <nav aria-label={t('dashboards.picker')} className="flex flex-wrap gap-1.5">
            {list.data.map((item) => (
              <button
                key={item.id}
                type="button"
                onClick={() => {
                  discard();
                  setChosenId(item.id);
                }}
                aria-current={item.id === selectedId ? 'true' : undefined}
                className={cn(
                  'focus-visible:ring-accent/40 flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm',
                  'ease-standard transition-colors duration-[var(--ds-duration-fast)] focus-visible:ring-2 focus-visible:outline-none',
                  item.id === selectedId
                    ? 'border-accent bg-accent-subtle text-content-brand font-medium'
                    : 'border-line text-content-secondary hover:bg-surface-hover',
                )}
              >
                <LayoutGrid aria-hidden className="size-3.5" />
                {item.name}
                {/* Whose it is, so a reader knows before they try to change it. */}
                {!item.can_manage && (
                  <span className="text-content-tertiary text-xs">
                    · {item.author_name ?? t('dashboards.shared')}
                  </span>
                )}
              </button>
            ))}
          </nav>
        )
      )}

      {saveError !== null && <Alert tone="danger">{saveError}</Alert>}

      {/* Editing chrome */}
      {isEditing && draft !== null && (
        <div className="bg-surface border-line-subtle flex flex-col gap-3 rounded-2xl border px-4 py-3">
          <div className="flex flex-wrap items-end gap-3">
            <label className="flex min-w-48 flex-1 flex-col gap-1">
              <span className="text-content text-xs font-medium">{t('dashboards.nameLabel')}</span>
              <Input
                value={draft.name}
                onChange={(event) =>
                  setDraft((current) =>
                    current === null ? current : { ...current, name: event.target.value },
                  )
                }
                maxLength={120}
              />
            </label>

            <fieldset className="flex flex-col gap-1">
              <legend className="text-content text-xs font-medium">
                {t('dashboards.visibilityLabel')}
              </legend>
              <div className="flex gap-1.5">
                {VISIBILITIES.map((visibility) => (
                  <button
                    key={visibility}
                    type="button"
                    aria-pressed={draft.visibility === visibility}
                    onClick={() =>
                      setDraft((current) =>
                        current === null ? current : { ...current, visibility },
                      )
                    }
                    className={cn(
                      'focus-visible:ring-accent/40 rounded-md border px-2.5 py-1.5 text-xs font-medium',
                      'focus-visible:ring-2 focus-visible:outline-none',
                      draft.visibility === visibility
                        ? 'border-accent bg-accent-subtle text-content-brand'
                        : 'border-line text-content-secondary hover:bg-surface-hover',
                    )}
                  >
                    {t(`dashboards.visibility.${visibility}`)}
                  </button>
                ))}
              </div>
            </fieldset>

            <div className="flex items-center gap-2">
              <Button variant="ghost" onClick={discard}>
                <X aria-hidden className="size-4" />
                {t('common.cancel')}
              </Button>
              <Button
                onClick={() => void commit()}
                disabled={save.isPending || draft.name.trim() === ''}
              >
                <Save aria-hidden className="size-4" />
                {save.isPending ? t('dashboards.saving') : t('dashboards.save')}
              </Button>
            </div>
          </div>

          <p className="text-content-tertiary text-xs">{t('dashboards.editHint')}</p>
        </div>
      )}

      {/* Global filters, which every widget inherits unless it overrides them */}
      {(isEditing ? draft : dashboard) !== null && (
        <FilterBar
          preset={(isEditing ? draft?.filters : dashboard?.filters)?.preset ?? '28d'}
          onPresetChange={(preset) =>
            isEditing
              ? setDraft((current) =>
                  current === null
                    ? current
                    : { ...current, filters: { ...current.filters, preset } },
                )
              : undefined
          }
          comparison={(isEditing ? draft?.filters : dashboard?.filters)?.compare ?? 'none'}
          onComparisonChange={(compare) =>
            isEditing
              ? setDraft((current) =>
                  current === null
                    ? current
                    : { ...current, filters: { ...current.filters, compare } },
                )
              : undefined
          }
          isRefreshing={detail.isFetching && !detail.isPending}
        />
      )}

      {/* The canvas */}
      {detail.isPending && selectedId !== null && !isEditing ? (
        <div className="grid gap-4 md:grid-cols-2">
          <Skeleton className="h-48" />
          <Skeleton className="h-48" />
        </div>
      ) : isEditing && draft !== null ? (
        <>
          <DashboardCanvas
            widgets={draft.widgets}
            filters={draft.filters}
            isEditing
            onMove={(index, delta) =>
              setDraft((current) =>
                current === null
                  ? current
                  : { ...current, widgets: move(current.widgets, index, delta) },
              )
            }
            onResize={(index, size) =>
              setDraft((current) =>
                current === null
                  ? current
                  : { ...current, widgets: resize(current.widgets, index, size) },
              )
            }
            onEdit={(index) => setEditingWidget(index)}
            onRemove={(index) =>
              setDraft((current) =>
                current === null
                  ? current
                  : {
                      ...current,
                      widgets: reflow(current.widgets.filter((_, position) => position !== index)),
                    },
              )
            }
          />

          {editingWidget === null ? (
            <Button
              variant="secondary"
              onClick={() => setEditingWidget('new')}
              className="self-start"
            >
              <Plus aria-hidden className="size-4" />
              {t('dashboards.addWidget')}
            </Button>
          ) : (
            <div className="bg-surface border-line-subtle rounded-2xl border px-4 py-4">
              <h2 className="text-content mb-3 text-sm font-semibold">
                {editingWidget === 'new' ? t('dashboards.addWidget') : t('dashboards.editWidget')}
              </h2>
              <WidgetEditor
                {...(typeof editingWidget === 'number' && draft.widgets[editingWidget] !== undefined
                  ? { widget: draft.widgets[editingWidget] }
                  : {})}
                onSave={applyWidget}
                onCancel={() => setEditingWidget(null)}
              />
            </div>
          )}
        </>
      ) : dashboard !== null ? (
        <>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-content-secondary text-sm">
              {dashboard.description ??
                t('dashboards.widgetCount', { count: dashboard.widgets.length })}
            </p>
            {canBuild && (
              <div className="flex items-center gap-2">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() =>
                    void duplicate.mutateAsync({ id: dashboard.id }).then((copy) => {
                      setChosenId(copy.id);
                    })
                  }
                  disabled={duplicate.isPending}
                >
                  <Copy aria-hidden className="size-4" />
                  {t('dashboards.duplicate')}
                </Button>
                {dashboard.can_manage && (
                  <>
                    <Button variant="secondary" size="sm" onClick={() => startEditing(dashboard)}>
                      <Pencil aria-hidden className="size-4" />
                      {t('dashboards.edit')}
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() =>
                        void remove.mutateAsync(dashboard.id).then(() => setChosenId(null))
                      }
                      disabled={remove.isPending}
                    >
                      <Trash2 aria-hidden className="size-4" />
                      {t('common.delete')}
                    </Button>
                  </>
                )}
              </div>
            )}
          </div>

          {dashboard.widgets.length === 0 ? (
            <EmptyState
              title={t('dashboards.emptyCanvas.title')}
              description={t('dashboards.emptyCanvas.description')}
            />
          ) : (
            <DashboardCanvas widgets={dashboard.widgets.map(toDraft)} filters={dashboard.filters} />
          )}
        </>
      ) : (
        <EmptyState
          title={t('dashboards.none.title')}
          description={t('dashboards.none.description')}
          {...(canBuild
            ? {
                action: (
                  <Button onClick={startNew}>
                    <Plus aria-hidden className="size-4" />
                    {t('dashboards.create')}
                  </Button>
                ),
              }
            : {})}
        />
      )}
    </div>
  );
}

function Heading() {
  const { t } = useTranslation();

  return <PageHeader title={t('dashboards.title')} description={t('dashboards.subtitle')} />;
}
