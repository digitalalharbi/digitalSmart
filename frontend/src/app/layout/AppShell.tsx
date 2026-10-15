import { Outlet } from 'react-router';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { useUiStore } from '@/shared/store/ui-store';
import { MobileNavDrawer } from './MobileNavDrawer';
import { Sidebar } from './Sidebar';
import { CommandPalette } from './CommandPalette';
import { Topbar } from './Topbar';

/**
 * The application canvas.
 *
 * One product surface rather than a set of screens: the rail and the topbar are chrome on the canvas, and the
 * page content floats on it as cards. Two things carry that reading — the canvas is a measurable step darker
 * than a card, and the outer gutter is generous and the same everywhere (`--ds-page-gutter`).
 *
 * The rail is **inset** rather than full-bleed: it sits inside the gutter with the content beside it, which is
 * what keeps a 1 440px screen from reading as a strip of chrome glued to the window edge.
 */
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
          isCollapsed ? 'lg:grid-cols-[5rem_1fr]' : 'lg:grid-cols-[17rem_1fr]',
        )}
      >
        <aside className="sticky top-0 hidden h-dvh p-3 lg:block">
          <Sidebar />
        </aside>

        <div className="flex min-w-0 flex-col">
          <Topbar />
          <CommandPalette />
          <main
            id="main"
            className="mx-auto w-full max-w-[96rem] flex-1 px-[var(--ds-page-gutter)] pt-2 pb-10 lg:px-[var(--ds-page-gutter-lg)] lg:pb-14"
          >
            <Outlet />
          </main>
        </div>
      </div>
    </div>
  );
}
