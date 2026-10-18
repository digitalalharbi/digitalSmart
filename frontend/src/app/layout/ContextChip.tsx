import { useTranslation } from 'react-i18next';
import { X } from 'lucide-react';
import { useUiStore } from '@/shared/store/ui-store';

/**
 * The working context — the client or project every page is currently narrowed to.
 *
 * Always visible when set, because it narrows every surface that reads it and a silent narrowing is a dashboard
 * that looks complete and is not. It sits at the top of the page content rather than in the header, where it used
 * to push the navigation around.
 */
export function ContextChip() {
  const { t } = useTranslation();
  const context = useUiStore((state) => state.context);
  const setContext = useUiStore((state) => state.setContext);
  const scope = context.project ?? context.client;

  if (scope === null) {
    return null;
  }

  return (
    <div className="mb-3 flex">
      <span className="bg-accent-subtle text-content-brand inline-flex h-9 max-w-full items-center gap-2 rounded-full ps-4 pe-2 text-sm">
        <span className="truncate" dir="auto">
          {scope.label}
        </span>
        <button
          type="button"
          aria-label={t('palette.clearContext')}
          onClick={() => setContext({ client: null, project: null })}
          className="hover:bg-surface/60 rounded-full p-1"
        >
          <X aria-hidden className="size-3.5" />
        </button>
      </span>
    </div>
  );
}
