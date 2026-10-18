import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { Languages, Moon, PanelsTopLeft, Search, Sun } from 'lucide-react';
import { NotificationBell } from '@/features/notifications';
import { AccountMenu } from './AccountMenu';
import { TopNav } from './TopNav';
import { Button } from '@/shared/ui/button';
import { useUiStore, resolveTheme } from '@/shared/store/ui-store';
import type { Locale } from '@/shared/i18n';

export function Topbar() {
  const { t } = useTranslation();
  const { theme, locale, setTheme, setLocale, setMobileNavOpen, setPaletteOpen } = useUiStore();
  const resolved = resolveTheme(theme);
  const nextLocale: Locale = locale === 'en' ? 'ar' : 'en';

  return (
    /*
     * The reference's header row: a logo pill, the navigation pill centred, and the actions on the right, all
     * floating on the workspace with nothing drawn behind them. It is sticky with a soft wash of the workspace
     * colour so content scrolling beneath stays legible without a bar being drawn.
     */
    <header className="bg-workspace/90 sticky top-0 z-20 flex h-[4.5rem] items-center gap-2 px-3 backdrop-blur-md sm:gap-3 sm:px-[var(--ds-page-gutter)] lg:rounded-t-[var(--ds-radius-workspace)] lg:px-6">
      {/* Below lg the rail is hidden, so this opens the drawer instead. */}
      <Button
        variant="ghost"
        size="icon"
        onClick={() => setMobileNavOpen(true)}
        aria-label={t('actions.openNavigation')}
        className="lg:hidden"
      >
        <PanelsTopLeft />
      </Button>

      <Link
        to="/"
        aria-label={t('brand.name')}
        className="bg-surface flex h-12 shrink-0 items-center gap-2.5 rounded-full ps-2 pe-5 shadow-xs"
      >
        <span
          aria-hidden="true"
          className="bg-accent-strong text-accent-contrast grid size-8 place-items-center rounded-full text-xs font-bold"
        >
          dS
        </span>
        <span className="text-content hidden text-sm font-semibold sm:inline">
          {t('brand.name')}
        </span>
      </Link>

      <div className="flex min-w-0 flex-1 justify-center">
        <TopNav />
      </div>

      {/* Search, alerts, language and appearance share one white pill, as in the reference. */}
      <div className="bg-surface ms-auto flex h-12 items-center gap-0.5 rounded-full p-1 shadow-xs lg:ms-0">
        <Button
          variant="ghost"
          size="icon"
          onClick={() => setPaletteOpen(true)}
          aria-label={t('actions.search')}
          title={`${t('actions.search')} (⌘K)`}
          className="rounded-full"
        >
          <Search />
        </Button>
        {/*
          The bell lives here because it is the one control present on every screen, which is also why it owns
          the realtime subscription: a notification arriving while somebody reads a chart still moves the number.
        */}
        <NotificationBell />
        <Button
          variant="ghost"
          size="icon"
          onClick={() => setLocale(nextLocale)}
          aria-label={t('actions.toggleLanguage')}
          title={t('actions.toggleLanguage')}
          className="rounded-full"
        >
          <Languages />
        </Button>
        <Button
          variant="ghost"
          size="icon"
          onClick={() => setTheme(resolved === 'dark' ? 'light' : 'dark')}
          aria-label={t('actions.toggleTheme')}
          title={t('actions.toggleTheme')}
          className="rounded-full"
        >
          {resolved === 'dark' ? <Sun /> : <Moon />}
        </Button>
      </div>

      <div className="bg-surface h-12 shrink-0 rounded-full shadow-xs">
        <AccountMenu />
      </div>
    </header>
  );
}
