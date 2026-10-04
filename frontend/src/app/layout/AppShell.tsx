import { Outlet } from 'react-router';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { useUiStore } from '@/shared/store/ui-store';
import { MobileNavDrawer } from './MobileNavDrawer';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';

export function AppShell() {
  const { t } = useTranslation();
  const isCollapsed = useUiStore((state) => state.isSidebarCollapsed);

  return (
    <div className="bg-canvas min-h-dvh">
      <a
        href="#main"
        className="bg-accent text-accent-contrast sr-only rounded-md px-3 py-2 text-sm focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50"
      >
        {t('a11y.skipToContent')}
      </a>

      <MobileNavDrawer />

      <div
        className={cn(
          'ease-standard grid min-h-dvh transition-[grid-template-columns] duration-[var(--ds-duration-base)]',
          isCollapsed ? 'lg:grid-cols-[4rem_1fr]' : 'lg:grid-cols-[16rem_1fr]',
        )}
      >
        <aside className="sticky top-0 hidden h-dvh lg:block">
          <Sidebar />
        </aside>

        <div className="flex min-w-0 flex-col">
          <Topbar />
          <main id="main" className="mx-auto w-full max-w-[90rem] flex-1 px-4 py-6 md:px-6 lg:py-8">
            <Outlet />
          </main>
        </div>
      </div>
    </div>
  );
}
