import { Outlet } from 'react-router';
import { useTranslation } from 'react-i18next';
import { MobileNavDrawer } from './MobileNavDrawer';
import { MobileTabBar } from './MobileTabBar';
import { SandboxNotice } from './SandboxNotice';
import { SubscriptionNotice } from './SubscriptionNotice';
import { IconRail } from './IconRail';
import { CommandPalette } from './CommandPalette';
import { Topbar } from './Topbar';
import { AreaTabs } from './AreaTabs';
import { ContextChip } from './ContextChip';

/**
 * The application frame.
 *
 * A light-grey page, and on it one large rounded workspace that holds everything: the header row (logo, navigation
 * pill, actions), the compact icon rail, and the page's cards. Three surfaces, three greys — page, workspace, card —
 * and the separation between them is the whole visual system: no heavy borders, almost no shadow.
 *
 * Below `lg` the workspace fills the screen and the rail gives way to the drawer.
 */
export function AppShell() {
  const { t } = useTranslation();

  return (
    <div className="bg-canvas min-h-dvh lg:p-5">
      <a
        href="#main"
        className="bg-action text-action-contrast sr-only rounded-md px-3 py-2 text-sm focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50"
      >
        {t('a11y.skipToContent')}
      </a>

      <MobileNavDrawer />
      <MobileTabBar />

      <div className="bg-workspace relative flex min-h-dvh flex-col lg:min-h-[calc(100dvh-2.5rem)] lg:rounded-[var(--ds-radius-workspace)]">
        <Topbar />
        <CommandPalette />

        {/* Room under the content for the phone's tab bar, so the last card is never behind it. */}
        <div className="flex flex-1 items-start gap-5 px-[var(--ds-page-gutter)] pb-28 lg:px-6 lg:pb-12">
          <aside className="sticky top-24 hidden shrink-0 lg:block">
            <IconRail />
          </aside>

          <main id="main" className="mx-auto w-full max-w-[96rem] min-w-0 flex-1 pt-2">
            <SubscriptionNotice />
            <SandboxNotice />
            <AreaTabs />
            <ContextChip />
            <Outlet />
          </main>
        </div>
      </div>
    </div>
  );
}
