import { NavLink } from 'react-router';
import { useTranslation } from 'react-i18next';
import { NAV_ITEMS, NAV_SECTIONS } from '@/app/navigation';
import { cn } from '@/shared/lib/cn';
import { useUiStore } from '@/shared/store/ui-store';
import { NavIcon } from './NavIcon';

export function Sidebar() {
  const { t } = useTranslation();
  const isCollapsed = useUiStore((state) => state.isSidebarCollapsed);

  return (
    <nav
      aria-label={t('a11y.mainNavigation')}
      className={cn(
        'ds-material border-line-subtle flex h-full flex-col gap-6 border-e py-5',
        isCollapsed ? 'px-2' : 'px-3',
      )}
    >
      <div className={cn('flex items-center gap-2.5', isCollapsed ? 'justify-center' : 'px-2')}>
        <span
          aria-hidden="true"
          className="bg-accent text-accent-contrast grid size-8 shrink-0 place-items-center rounded-lg text-sm font-bold"
        >
          dS
        </span>
        {!isCollapsed ? (
          <span className="flex flex-col leading-tight">
            <span className="text-content text-sm font-semibold tracking-[-0.01em]">
              {t('brand.name')}
            </span>
            <span className="text-content-tertiary text-[0.6875rem]">{t('brand.by')}</span>
          </span>
        ) : null}
      </div>

      <div className="flex flex-1 flex-col gap-5 overflow-y-auto">
        {NAV_SECTIONS.map((section) => (
          <div key={section} className="flex flex-col gap-1">
            {!isCollapsed ? (
              <h2 className="text-content-tertiary px-2 py-1 text-[0.6875rem] font-semibold tracking-wider uppercase">
                {t(`nav.sections.${section}`)}
              </h2>
            ) : null}
            {NAV_ITEMS.filter((item) => item.section === section).map((item) => (
              <NavLink
                key={item.path}
                to={item.path}
                end={item.path === '/'}
                title={isCollapsed ? t(item.labelKey) : undefined}
                className={({ isActive }) =>
                  cn(
                    'group flex items-center gap-2.5 rounded-md text-sm font-medium',
                    'ease-standard transition-colors duration-[var(--ds-duration-fast)]',
                    isCollapsed ? 'justify-center p-2' : 'px-2 py-2',
                    isActive
                      ? 'bg-accent-subtle text-content-brand'
                      : 'text-content-secondary hover:bg-surface-hover hover:text-content',
                  )
                }
              >
                <NavIcon name={item.icon} className="size-[1.125rem] shrink-0" />
                {!isCollapsed ? <span className="truncate">{t(item.labelKey)}</span> : null}
              </NavLink>
            ))}
          </div>
        ))}
      </div>
    </nav>
  );
}
