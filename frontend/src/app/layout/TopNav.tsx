import { NavLink } from 'react-router';
import { useTranslation } from 'react-i18next';
import { NAV_ITEMS, PRIMARY_NAV_PATHS, type NavItem } from '@/app/navigation';
import { useSession, type PermissionSlug } from '@/features/auth';
import { cn } from '@/shared/lib/cn';

/**
 * The floating navigation pill.
 *
 * The handful of destinations used all day, on one white pill, with the current one a graphite pill inside it —
 * the reference's "Overview" treatment. It is the page's *main navigation*; the rail beside the content carries the
 * rest. Hidden below `lg`, where the drawer carries both.
 */
export function TopNav() {
  const { t } = useTranslation();
  const { can } = useSession();

  const items = NAV_ITEMS.filter(
    (item: NavItem) =>
      PRIMARY_NAV_PATHS.includes(item.path) &&
      (item.permission === undefined || can(item.permission as PermissionSlug)),
  );

  return (
    <nav
      aria-label={t('a11y.mainNavigation')}
      className="bg-surface hidden items-center gap-1 rounded-full p-1.5 shadow-xs lg:flex"
    >
      {items.map((item) => (
        <NavLink
          key={item.path}
          to={item.path}
          end={item.path === '/'}
          className={({ isActive }) =>
            cn(
              'rounded-full px-4 py-2 text-sm font-medium whitespace-nowrap',
              'ease-standard transition-colors duration-[var(--ds-duration-fast)]',
              isActive
                ? 'bg-action text-action-contrast'
                : 'text-content-secondary hover:bg-surface-hover hover:text-content',
            )
          }
        >
          {t(item.labelKey)}
        </NavLink>
      ))}
    </nav>
  );
}
