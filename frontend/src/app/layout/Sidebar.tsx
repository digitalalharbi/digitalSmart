import { NavLink } from 'react-router';
import { useTranslation } from 'react-i18next';
import { NAV_ITEMS, NAV_SECTIONS, type NavItem } from '@/app/navigation';
import { useSession, type PermissionSlug } from '@/features/auth';
import { cn } from '@/shared/lib/cn';
import { NavIcon } from './NavIcon';

/**
 * The full, labelled navigation — the drawer's content below `lg`.
 *
 * On a wide screen the same destinations are split between the top pill and the icon rail; on a phone there is no
 * room for either, so every destination is listed here with its name, in sections, the current one a graphite pill.
 */
export function Sidebar() {
  const { t } = useTranslation();
  const { can } = useSession();

  /*
   * Hiding a surface the role cannot use keeps the navigation honest. It is a
   * courtesy, not a security boundary — every endpoint re-checks on the server,
   * and reaching the route directly still returns 403.
   */
  const isVisible = (item: NavItem): boolean =>
    item.permission === undefined || can(item.permission as PermissionSlug);

  return (
    <nav aria-label={t('a11y.mainNavigation')} className="flex h-full flex-col gap-5 px-3 py-5">
      <div className="flex items-center gap-2.5 px-2">
        <span
          aria-hidden="true"
          className="bg-accent-strong text-accent-contrast grid size-9 shrink-0 place-items-center rounded-xl text-sm font-bold"
        >
          dS
        </span>
        <span className="flex min-w-0 flex-col leading-tight">
          <span className="text-content truncate text-sm font-semibold">{t('brand.name')}</span>
          <span className="text-content-tertiary truncate text-[0.6875rem]">{t('brand.by')}</span>
        </span>
      </div>

      <div className="flex flex-1 flex-col gap-4 overflow-y-auto">
        {NAV_SECTIONS.map((section) => {
          const items = NAV_ITEMS.filter((item) => item.section === section && isVisible(item));

          if (items.length === 0) {
            return null;
          }

          return (
            <div key={section} className="flex flex-col gap-0.5">
              <h2 className="text-content-tertiary ds-overline px-2.5 pt-2 pb-1.5">
                {t(`nav.sections.${section}`)}
              </h2>

              {items.map((item) => (
                <NavLink
                  key={item.path}
                  to={item.path}
                  end={item.path === '/'}
                  className={({ isActive }) =>
                    cn(
                      'flex items-center gap-3 rounded-full px-3 py-2 text-sm font-medium',
                      'ease-standard transition-colors duration-[var(--ds-duration-fast)]',
                      isActive
                        ? 'bg-action text-action-contrast'
                        : 'text-content-secondary hover:bg-surface-hover hover:text-content',
                    )
                  }
                >
                  <NavIcon name={item.icon} className="size-[1.125rem] shrink-0" />
                  <span className="truncate">{t(item.labelKey)}</span>
                </NavLink>
              ))}
            </div>
          );
        })}
      </div>
    </nav>
  );
}
