import { NavLink } from 'react-router';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { useAreas } from './useAreas';

/**
 * The floating navigation pill: the few areas this kind of person works in all day.
 *
 * The current area is the graphite pill — and it stays lit on every page inside the area, not only its first, so a
 * person four clicks deep still knows where they are. Hidden below `lg`, where the drawer lists every area.
 */
export function TopNav() {
  const { t } = useTranslation();
  const { top, current } = useAreas();

  return (
    <nav
      aria-label={t('a11y.mainNavigation')}
      className="bg-surface hidden items-center gap-1 rounded-full p-1.5 shadow-xs lg:flex"
    >
      {top.map((area) => (
        <NavLink
          key={area.key}
          to={area.home}
          aria-current={current === area.key ? 'page' : undefined}
          className={cn(
            'rounded-full px-4 py-2 text-sm font-medium whitespace-nowrap',
            'ease-standard transition-colors duration-[var(--ds-duration-fast)]',
            current === area.key
              ? 'bg-action text-action-contrast'
              : 'text-content-secondary hover:bg-surface-hover hover:text-content',
          )}
        >
          {t(area.labelKey)}
        </NavLink>
      ))}
    </nav>
  );
}
