import * as Dialog from '@radix-ui/react-dialog';
import { Bookmark, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { useDeleteSurfaceView, useSaveSurfaceView, useSurfaceViews } from './api';
import type { SurfaceFilters, SurfaceKind, SurfaceView, ViewVisibility } from './types';

type SavedViewsMenuProps = {
  surface: SurfaceKind;
  /** The filters as they stand, in the page's own URL vocabulary. Empty values are dropped before saving. */
  current: SurfaceFilters;
  onApply: (filters: SurfaceFilters) => void;
  /** The project the page is narrowed to, if any — the only team a view can be shared with from here. */
  project?: { id: string; label: string } | null;
};

/** A stored filter value is a string or a list; a page's URL holds one string. */
function flatten(filters: SurfaceView['filters']): SurfaceFilters {
  const out: SurfaceFilters = {};

  for (const [key, value] of Object.entries(filters)) {
    const single = Array.isArray(value) ? value[0] : value;

    if (single !== undefined && single !== '') {
      out[key] = single;
    }
  }

  return out;
}

/**
 * Saved filter sets for an agency surface.
 *
 * Applying one is only ever *setting the page's own filters* — the server does not run a view, it returns the
 * choices, and the page asks its usual question with them. So a view can never show somebody more than the page
 * would have shown them with the same filters typed by hand.
 */
export function SavedViewsMenu({ surface, current, onApply, project = null }: SavedViewsMenuProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [visibility, setVisibility] = useState<ViewVisibility>('private');
  const views = useSurfaceViews(surface, open);
  const save = useSaveSurfaceView(surface);
  const remove = useDeleteSurfaceView(surface);

  const clean = Object.fromEntries(Object.entries(current).filter(([, value]) => value !== ''));
  const canSave = name.trim().length > 0 && Object.keys(clean).length > 0 && !save.isPending;

  return (
    <Dialog.Root open={open} onOpenChange={setOpen}>
      <Dialog.Trigger asChild>
        <Button variant="secondary" size="sm">
          <Bookmark aria-hidden className="size-3.5" />
          {t('surfaceViews.open')}
        </Button>
      </Dialog.Trigger>

      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40" />
        <Dialog.Content className="bg-surface fixed start-1/2 top-1/2 z-50 flex max-h-[85vh] w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 flex-col gap-4 overflow-y-auto rounded-xl p-6 shadow-lg rtl:translate-x-1/2">
          <div>
            <Dialog.Title className="text-content text-lg font-semibold">
              {t('surfaceViews.title')}
            </Dialog.Title>
            <Dialog.Description className="text-content-secondary mt-1 text-sm">
              {t('surfaceViews.body')}
            </Dialog.Description>
          </div>

          {views.isPending ? (
            <p className="text-content-tertiary text-sm">{t('surfaceViews.loading')}</p>
          ) : (views.data ?? []).length === 0 ? (
            <p className="text-content-tertiary text-sm">{t('surfaceViews.empty')}</p>
          ) : (
            <ul className="flex flex-col gap-1.5">
              {(views.data ?? []).map((view) => (
                <li
                  key={view.id}
                  className="border-line-subtle flex items-center justify-between gap-2 rounded-md border px-3 py-2"
                >
                  <button
                    type="button"
                    className="flex min-w-0 flex-col text-start"
                    onClick={() => {
                      onApply(flatten(view.filters));
                      setOpen(false);
                    }}
                  >
                    <span className="text-content truncate text-sm font-medium">{view.name}</span>
                    <span className="text-content-tertiary truncate text-xs">
                      {view.visibility === 'project' && view.project_name !== null
                        ? view.project_name
                        : view.visibility_label}
                      {view.is_mine || view.author_name === null ? '' : ` · ${view.author_name}`}
                    </span>
                  </button>

                  {view.is_mine ? (
                    <Button
                      variant="ghost"
                      size="sm"
                      aria-label={t('surfaceViews.delete', { name: view.name })}
                      disabled={remove.isPending}
                      onClick={() => remove.mutate(view.id)}
                    >
                      <Trash2 aria-hidden className="size-3.5" />
                    </Button>
                  ) : null}
                </li>
              ))}
            </ul>
          )}

          <form
            className="border-line-subtle flex flex-col gap-3 border-t pt-4"
            onSubmit={(event) => {
              event.preventDefault();
              save.mutate(
                {
                  name: name.trim(),
                  visibility,
                  projectId: visibility === 'project' ? (project?.id ?? null) : null,
                  filters: clean,
                },
                {
                  onSuccess: () => {
                    setName('');
                    setVisibility('private');
                  },
                },
              );
            }}
          >
            <Field label={t('surfaceViews.saveCurrent')}>
              {(field) => (
                <Input
                  {...field}
                  value={name}
                  maxLength={120}
                  placeholder={t('surfaceViews.namePlaceholder')}
                  onChange={(event) => setName(event.target.value)}
                />
              )}
            </Field>

            <ChoiceGroup label={t('surfaceViews.shareWith')}>
              <div className="flex flex-wrap gap-2">
                <Choice
                  isSelected={visibility === 'private'}
                  onSelect={() => setVisibility('private')}
                >
                  {t('surfaceViews.visibility.private')}
                </Choice>
                <Choice
                  isSelected={visibility === 'project'}
                  disabled={project === null}
                  onSelect={() => setVisibility('project')}
                >
                  {project === null
                    ? t('surfaceViews.visibility.project')
                    : t('surfaceViews.visibility.projectNamed', { name: project.label })}
                </Choice>
                <Choice
                  isSelected={visibility === 'workspace'}
                  onSelect={() => setVisibility('workspace')}
                >
                  {t('surfaceViews.visibility.workspace')}
                </Choice>
              </div>
            </ChoiceGroup>

            {Object.keys(clean).length === 0 ? (
              <p className="text-content-tertiary text-xs">{t('surfaceViews.nothingToSave')}</p>
            ) : null}

            {save.isError ? (
              <Alert tone="danger">
                {save.error instanceof ApiError ? save.error.message : t('surfaceViews.failed')}
              </Alert>
            ) : null}

            <div className="flex justify-end gap-2">
              <Dialog.Close asChild>
                <Button type="button" variant="ghost">
                  {t('actions.close')}
                </Button>
              </Dialog.Close>
              <Button type="submit" disabled={!canSave}>
                {t('surfaceViews.save')}
              </Button>
            </div>
          </form>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
