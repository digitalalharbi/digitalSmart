import * as Dialog from '@radix-ui/react-dialog';
import { Pin, PinOff, Search as SearchIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { returnFocus } from '@/shared/ui/return-focus';
import { useSession } from '@/features/auth';
import {
  usePinMutation,
  useSearchQuery,
  useShortcutsQuery,
  useVisit,
  type SearchHit,
} from '@/features/search';
import { NAV_ITEMS } from '@/app/navigation';
import { useUiStore } from '@/shared/store/ui-store';
import { cn } from '@/shared/lib/cn';

type Entry = {
  key: string;
  label: string;
  context: string | null;
  kind: string;
  run: () => void;
  hit?: SearchHit;
};

/**
 * Find anything in the agency, and go there.
 *
 * Empty, it offers what is already close — pinned, then recent, then the pages. Typed into, it asks the
 * server once for every entity type at once. Nothing is loaded until two characters, and the list is the
 * server's top few per type: at a hundred projects the only usable shape is a search box, never a menu.
 *
 * Choosing a client or project can also *set the working context*, which narrows the surfaces that read it.
 * Both actions are offered, because "take me there" and "work inside it" are different intentions.
 */
export function CommandPalette() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { can } = useSession();
  const { isPaletteOpen, setPaletteOpen, setContext, context } = useUiStore();
  const [term, setTerm] = useState('');
  const [active, setActive] = useState(0);

  const search = useSearchQuery(term);
  const shortcuts = useShortcutsQuery(isPaletteOpen);
  const pin = usePinMutation();
  const visit = useVisit();

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        setPaletteOpen(!useUiStore.getState().isPaletteOpen);
      }
    };

    window.addEventListener('keydown', onKey);

    return () => window.removeEventListener('keydown', onKey);
  }, [setPaletteOpen]);

  const close = () => {
    setPaletteOpen(false);
    setTerm('');
    setActive(0);
  };

  const open = (hit: SearchHit) => {
    visit(hit);
    close();
    void navigate(hit.path);
  };

  const pinned = new Set((shortcuts.data?.pinned ?? []).map((h) => `${h.type}:${h.id}`));

  const entries = useMemo<Entry[]>(() => {
    const hitEntry = (hit: SearchHit, kind: string): Entry => ({
      key: `${kind}:${hit.type}:${hit.id}`,
      label: hit.label,
      context: hit.context,
      kind,
      hit,
      run: () => open(hit),
    });

    if (term.trim().length >= 2) {
      return (search.data ?? []).map((hit) => hitEntry(hit, t(`palette.type.${hit.type}`)));
    }

    const pages: Entry[] = NAV_ITEMS.filter(
      (item) => item.permission === undefined || can(item.permission as never),
    ).map((item) => ({
      key: `page:${item.path}`,
      label: t(item.labelKey),
      context: null,
      kind: t('palette.pages'),
      run: () => {
        close();
        void navigate(item.path);
      },
    }));

    return [
      ...(shortcuts.data?.pinned ?? []).map((h) => hitEntry(h, t('palette.pinned'))),
      ...(shortcuts.data?.recent ?? []).map((h) => hitEntry(h, t('palette.recent'))),
      ...pages,
    ];
    // `open` and `close` close over stable setters; the data they read is in the dependency list.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [term, search.data, shortcuts.data, t, can]);

  const current = entries[Math.min(active, Math.max(entries.length - 1, 0))];

  return (
    <Dialog.Root
      open={isPaletteOpen}
      onOpenChange={(next) => (next ? setPaletteOpen(true) : close())}
    >
      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40" />
        <Dialog.Content
          aria-describedby={undefined}
          onCloseAutoFocus={returnFocus}
          className="bg-surface border-line-subtle fixed start-1/2 top-[12vh] z-50 flex max-h-[70dvh] w-[calc(100vw-2rem)] max-w-xl -translate-x-1/2 flex-col overflow-hidden rounded-2xl border shadow-lg rtl:translate-x-1/2"
        >
          <Dialog.Title className="sr-only">{t('palette.title')}</Dialog.Title>
          <div className="border-line-subtle flex items-center gap-3 border-b px-4">
            <SearchIcon aria-hidden className="text-content-tertiary size-4 shrink-0" />
            <input
              autoFocus
              role="combobox"
              aria-expanded
              aria-controls="palette-list"
              aria-activedescendant={current === undefined ? undefined : `palette-${current.key}`}
              value={term}
              placeholder={t('palette.placeholder')}
              onChange={(event) => {
                setTerm(event.target.value);
                setActive(0);
              }}
              onKeyDown={(event) => {
                if (event.key === 'ArrowDown') {
                  event.preventDefault();
                  setActive((a) => Math.min(a + 1, entries.length - 1));
                } else if (event.key === 'ArrowUp') {
                  event.preventDefault();
                  setActive((a) => Math.max(a - 1, 0));
                } else if (event.key === 'Enter' && current !== undefined) {
                  event.preventDefault();
                  current.run();
                }
              }}
              className="text-content placeholder:text-content-tertiary h-14 w-full bg-transparent text-sm outline-none"
            />
          </div>

          <ul
            id="palette-list"
            role="listbox"
            aria-label={t('palette.title')}
            className="overflow-y-auto p-2"
          >
            {term.trim().length >= 2 && !search.isPending && entries.length === 0 ? (
              <li className="text-content-tertiary px-3 py-6 text-center text-sm">
                {t('palette.none')}
              </li>
            ) : null}

            {entries.map((entry, index) => (
              <li
                key={entry.key}
                id={`palette-${entry.key}`}
                role="option"
                aria-selected={index === active}
                className={cn(
                  'flex cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm',
                  index === active ? 'bg-accent-subtle' : 'hover:bg-surface-hover',
                )}
                onMouseEnter={() => setActive(index)}
                onClick={entry.run}
              >
                <span className="flex min-w-0 flex-col">
                  <span className="text-content truncate">{entry.label}</span>
                  {entry.context === null ? null : (
                    <span className="text-content-tertiary truncate text-xs">{entry.context}</span>
                  )}
                </span>
                <span className="flex shrink-0 items-center gap-2">
                  {entry.hit !== undefined &&
                  (entry.hit.type === 'client' || entry.hit.type === 'project') ? (
                    <button
                      type="button"
                      className="text-content-tertiary hover:text-content rounded px-2 py-1 text-xs"
                      onClick={(event) => {
                        event.stopPropagation();
                        const hit = entry.hit;

                        if (hit === undefined) {
                          return;
                        }

                        setContext({
                          client:
                            hit.type === 'client'
                              ? { id: hit.id, label: hit.label }
                              : context.client,
                          project: hit.type === 'project' ? { id: hit.id, label: hit.label } : null,
                        });
                        close();
                      }}
                    >
                      {t('palette.workInside')}
                    </button>
                  ) : null}
                  {entry.hit !== undefined &&
                  (entry.hit.type === 'client' ||
                    entry.hit.type === 'project' ||
                    entry.hit.type === 'campaign') ? (
                    <button
                      type="button"
                      aria-label={t(
                        pinned.has(`${entry.hit.type}:${entry.hit.id}`)
                          ? 'palette.unpin'
                          : 'palette.pin',
                        { name: entry.label },
                      )}
                      className="text-content-tertiary hover:text-content rounded p-1"
                      onClick={(event) => {
                        event.stopPropagation();
                        const hit = entry.hit;

                        if (hit !== undefined) {
                          pin.mutate({ hit, pinned: !pinned.has(`${hit.type}:${hit.id}`) });
                        }
                      }}
                    >
                      {entry.hit !== undefined &&
                      pinned.has(`${entry.hit.type}:${entry.hit.id}`) ? (
                        <PinOff aria-hidden className="size-4" />
                      ) : (
                        <Pin aria-hidden className="size-4" />
                      )}
                    </button>
                  ) : null}
                  <span className="text-content-tertiary text-xs">{entry.kind}</span>
                </span>
              </li>
            ))}
          </ul>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
