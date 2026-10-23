import { NavLink } from 'react-router';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { Logo } from '@/shared/ui/logo';
import { NavIcon } from './NavIcon';
import { useAreas } from './useAreas';

/**
 * The full, labelled navigation — the drawer's content below `lg`.
 *
 * On a wide screen the same destinations are split between the top pill and the icon rail; on a phone there is no
 * room for either, so every destination is listed here with its name, in sections, the current one a graphite pill.
 */
export function Sidebar() {
  const { t } = useTranslation();
  const { all, current } = useAreas();

  return (
    <nav aria-label={t('a11y.mainNavigation')} className="flex h-full flex-col gap-5 px-3 py-5">
      <div className="flex items-center px-2">
        <Logo layout="horizontal" width={132} label={t('brand.name')} />
      </div>

      <div className="flex flex-1 flex-col gap-1 overflow-y-auto">
        {all.map((area) => (
          <NavLink
            key={area.key}
            to={area.home}
            aria-current={current === area.key ? 'page' : undefined}
            className={cn(
              'flex items-center gap-3 rounded-full px-3 py-2.5 text-sm font-medium',
              'ease-standard transition-colors duration-[var(--ds-duration-fast)]',
              current === area.key
                ? 'bg-action text-action-contrast'
                : 'text-content-secondary hover:bg-surface-hover hover:text-content',
            )}
          >
            <NavIcon name={area.icon} className="size-[1.125rem] shrink-0" />
            <span className="truncate">{t(area.labelKey)}</span>
          </NavLink>
        ))}
      </div>
    </nav>
  );
}
