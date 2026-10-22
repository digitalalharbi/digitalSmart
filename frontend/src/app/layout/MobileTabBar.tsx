import { NavLink } from 'react-router';
import { useTranslation } from 'react-i18next';
import { Ellipsis } from 'lucide-react';
import { cn } from '@/shared/lib/cn';
import { useUiStore } from '@/shared/store/ui-store';
import { NavIcon } from './NavIcon';
import { useAreas } from './useAreas';

/** How many areas sit in the bar. Four, and "More": five targets a thumb can hit without looking. */
const SLOTS = 4;

/**
 * The bar at the bottom of a phone: the areas a person uses most, one tap each, and "More" for the rest.
 *
 * A drawer behind a button is a menu somebody has to remember to open; this is where a thumb already is. It is the
 * same set of areas the top bar offers on a wide screen — chosen by the same role rules, so nobody is offered a place
 * they may not enter — and it hides at the breakpoint where the rail and top bar take over.
 *
 * It sits above the browser's home indicator (`env(safe-area-inset-bottom)`, zero where there is none) and every
 * target is at least 44px.
 */
export function MobileTabBar() {
  const { t } = useTranslation();
  const { top, current } = useAreas();
  const setOpen = useUiStore((state) => state.setMobileNavOpen);

  return (
    <nav
      aria-label={t('app.mobileTabs.label')}
      className="border-line bg-surface fixed inset-x-0 bottom-0 z-30 border-t pb-[env(safe-area-inset-bottom)] lg:hidden"
    >
      <ul className="mx-auto flex max-w-xl items-stretch justify-around px-2">
        {top.slice(0, SLOTS).map((area) => {
          const active = current === area.key;

          return (
            <li key={area.key} className="min-w-0 flex-1">
              <NavLink
                to={area.home}
                aria-current={active ? 'page' : undefined}
                className={cn(
                  'flex min-h-14 flex-col items-center justify-center gap-0.5 rounded-xl px-1 py-1.5 text-[0.6875rem] font-medium',
                  'focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none',
                  active ? 'text-content-brand' : 'text-content-secondary',
                )}
              >
                <span
                  className={cn(
                    'grid h-7 w-12 place-items-center rounded-full',
                    active && 'bg-accent-subtle',
                  )}
                >
                  <NavIcon name={area.icon} className="size-5" />
                </span>
                <span className="max-w-full truncate">{t(area.labelKey)}</span>
              </NavLink>
            </li>
          );
        })}
        <li className="min-w-0 flex-1">
          <button
            type="button"
            onClick={() => setOpen(true)}
            className="text-content-secondary focus-visible:ring-accent/40 flex min-h-14 w-full flex-col items-center justify-center gap-0.5 rounded-xl px-1 py-1.5 text-[0.6875rem] font-medium focus-visible:ring-2 focus-visible:outline-none"
          >
            <span className="grid h-7 w-12 place-items-center rounded-full">
              <Ellipsis aria-hidden className="size-5" />
            </span>
            <span>{t('app.mobileTabs.more')}</span>
          </button>
        </li>
      </ul>
    </nav>
  );
}
