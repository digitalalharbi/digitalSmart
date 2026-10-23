import { NavLink } from 'react-router';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { useAreas } from './useAreas';

/**
 * The tabs of the area a person is in.
 *
 * What used to be a dozen top-level pages are the sections of the ten areas, and this is how they are reached: the
 * same place, the same shape, on every page of the area. Nothing is drawn for an area with a single page, so a
 * one-page area is not dressed up as a set.
 */
export function AreaTabs() {
  const { t } = useTranslation();
  const { all, current } = useAreas();
  const area = all.find((candidate) => candidate.key === current);

  if (area === undefined || area.items.length < 2) {
    return null;
  }

  return (
    <nav aria-label={t('areas.tabs')} className="mb-5 [scrollbar-width:none] overflow-x-auto">
      <ul className="bg-surface inline-flex gap-1 rounded-full p-1 shadow-xs">
        {area.items.map((item) => (
          <li key={item.path}>
            <NavLink
              to={item.path}
              end
              className={({ isActive }) =>
                cn(
                  'block rounded-full px-3.5 py-1.5 text-sm font-medium whitespace-nowrap',
                  'ease-standard transition-colors duration-[var(--ds-duration-fast)]',
                  isActive
                    ? 'bg-approve text-approve-contrast'
                    : 'bg-surface-sunken text-content-secondary hover:bg-surface-hover hover:text-content',
                )
              }
            >
              {t(item.labelKey)}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  );
}
