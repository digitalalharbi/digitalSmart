import { NavLink } from 'react-router';
import { useTranslation } from 'react-i18next';
import { NAV_ITEMS, NAV_SECTIONS, type NavItem } from '@/app/navigation';
import { useSession, type PermissionSlug } from '@/features/auth';
import { cn } from '@/shared/lib/cn';
import { useUiStore } from '@/shared/store/ui-store';
import { NavIcon } from './NavIcon';

/**
 * The navigation rail.
 *
 * A panel on the canvas rather than a wall at the window's edge: it has its own surface, its own radius and
 * the same hairline every card has, so the shell and the content read as one system.
 *
 * **The active item is a filled pill**, not a tinted row. At a glance a reader should be able to find where
 * they are without reading a single label, and a background wash across a 17rem row is too diffuse to do that.
 *
 * **Collapsed is a real mode, not a narrower one.** Labels go, the pill becomes square, and the section
 * headings become a hairline — because a heading rendered as three truncated letters is noise.
 */
export function Sidebar() {
  const { t } = useTranslation();
  const isCollapsed = useUiStore((state) => state.isSidebarCollapsed);
  const { can } = useSession();

  /*
   * Hiding a surface the role cannot use keeps the navigation honest. It is a
   * courtesy, not a security boundary — every endpoint re-checks on the server,
   * and reaching the route directly still returns 403.
   */
  const isVisible = (item: NavItem): boolean =>
    item.permission === undefined || can(item.permission as PermissionSlug);

  return (
    <nav
      aria-label={t('a11y.mainNavigation')}
      className={cn(
        'bg-surface border-line-subtle flex h-full flex-col gap-5 rounded-2xl border shadow-xs',
        isCollapsed ? 'items-center px-2 py-4' : 'px-3 py-5',
      )}
    >
      <div className={cn('flex items-center gap-2.5', isCollapsed ? 'justify-center' : 'px-2')}>
        <span
          aria-hidden="true"
          className="bg-accent text-accent-contrast grid size-9 shrink-0 place-items-center rounded-xl text-sm font-bold"
        >
          dS
        </span>
        {!isCollapsed ? (
          <span className="flex min-w-0 flex-col leading-tight">
            <span className="text-content truncate text-sm font-semibold">{t('brand.name')}</span>
            <span className="text-content-tertiary truncate text-[0.6875rem]">{t('brand.by')}</span>
          </span>
        ) : null}
      </div>

      <div
        className={cn(
          'flex flex-1 flex-col gap-4 overflow-y-auto',
          isCollapsed ? 'w-full items-center' : '',
        )}
      >
        {NAV_SECTIONS.map((section) => {
          const items = NAV_ITEMS.filter((item) => item.section === section && isVisible(item));

          if (items.length === 0) {
            return null;
          }

          return (
            <div
              key={section}
              className={cn('flex flex-col gap-0.5', isCollapsed ? 'w-full items-center' : '')}
            >
              {isCollapsed ? (
                /* A rule rather than a truncated word: the grouping survives, the noise does not. */
                <span aria-hidden="true" className="bg-line-subtle my-2 h-px w-6" />
              ) : (
                <h2 className="text-content-tertiary ds-overline px-2.5 pt-2 pb-1.5">
                  {t(`nav.sections.${section}`)}
                </h2>
              )}

              {items.map((item) => (
                <NavLink
                  key={item.path}
                  to={item.path}
                  end={item.path === '/'}
                  title={isCollapsed ? t(item.labelKey) : undefined}
                  className={({ isActive }) =>
                    cn(
                      'group relative flex items-center gap-3 text-sm font-medium',
                      'ease-standard transition-colors duration-[var(--ds-duration-fast)]',
                      isCollapsed ? 'size-10 justify-center rounded-xl' : 'rounded-xl px-2.5 py-2',
                      isActive
                        ? 'bg-accent text-accent-contrast shadow-xs'
                        : 'text-content-secondary hover:bg-surface-hover hover:text-content',
                    )
                  }
                >
                  <NavIcon name={item.icon} className="size-[1.125rem] shrink-0" />
                  {!isCollapsed ? <span className="truncate">{t(item.labelKey)}</span> : null}
                </NavLink>
              ))}
            </div>
          );
        })}
      </div>
    </nav>
  );
}
