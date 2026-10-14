import { useTranslation } from 'react-i18next';
import { Languages, Moon, PanelsTopLeft, Search, Sun } from 'lucide-react';
import { NotificationBell } from '@/features/notifications';
import { AccountMenu } from './AccountMenu';
import { Button } from '@/shared/ui/button';
import { useUiStore, resolveTheme } from '@/shared/store/ui-store';
import type { Locale } from '@/shared/i18n';

export function Topbar() {
  const { t } = useTranslation();
  const { theme, locale, setTheme, setLocale, toggleSidebar, setMobileNavOpen } = useUiStore();
  const resolved = resolveTheme(theme);
  const nextLocale: Locale = locale === 'en' ? 'ar' : 'en';

  return (
    /*
     * Chrome on the canvas, not a bar across the window: no border, a translucent material so content scrolling
     * under it stays legible, and the same gutter as the page below so the search field lines up with the first
     * card rather than floating four pixels off it.
     */
    <header className="ds-material sticky top-0 z-20 flex h-16 items-center gap-2 px-[var(--ds-page-gutter)] lg:px-[var(--ds-page-gutter-lg)]">
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

      <Button
        variant="ghost"
        size="icon"
        onClick={toggleSidebar}
        aria-label={t('actions.collapseNavigation')}
        className="hidden lg:inline-flex"
      >
        <PanelsTopLeft />
      </Button>

      {/* Command palette lands in Phase 4; the affordance and its shortcut hint exist now. */}
      <button
        type="button"
        className="border-line-subtle bg-surface text-content-tertiary hover:border-line flex h-10 min-w-0 flex-1 items-center gap-2.5 rounded-full border px-4 text-sm shadow-xs transition-colors duration-[var(--ds-duration-fast)] md:max-w-md"
      >
        <Search className="size-4 shrink-0" aria-hidden="true" />
        <span className="truncate">{t('actions.search')}</span>
        <kbd className="border-line-subtle bg-surface-sunken text-content-tertiary ms-auto hidden rounded-md border px-1.5 py-0.5 font-mono text-[0.6875rem] md:inline-block">
          ⌘K
        </kbd>
      </button>

      {/* The action cluster sits on its own surface so it reads as one control group rather than four icons. */}
      <div className="border-line-subtle bg-surface ms-auto flex items-center gap-0.5 rounded-full border p-1 shadow-xs">
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
        >
          <Languages />
        </Button>
        <Button
          variant="ghost"
          size="icon"
          onClick={() => setTheme(resolved === 'dark' ? 'light' : 'dark')}
          aria-label={t('actions.toggleTheme')}
          title={t('actions.toggleTheme')}
        >
          {resolved === 'dark' ? <Sun /> : <Moon />}
        </Button>

        <AccountMenu />
      </div>
    </header>
  );
}
