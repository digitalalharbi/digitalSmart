import { useTranslation } from 'react-i18next';
import { Languages, Moon, PanelsTopLeft, Search, Sun } from 'lucide-react';
import { Button } from '@/shared/ui/button';
import { useUiStore, resolveTheme } from '@/shared/store/ui-store';
import type { Locale } from '@/shared/i18n';

export function Topbar() {
  const { t } = useTranslation();
  const { theme, locale, setTheme, setLocale, toggleSidebar, setMobileNavOpen } = useUiStore();
  const resolved = resolveTheme(theme);
  const nextLocale: Locale = locale === 'en' ? 'ar' : 'en';

  return (
    <header className="ds-material border-line-subtle sticky top-0 z-20 flex h-14 items-center gap-3 border-b px-4">
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
        className="border-line bg-surface-sunken text-content-tertiary hover:border-line-strong flex h-9 min-w-0 flex-1 items-center gap-2 rounded-md border px-3 text-sm transition-colors duration-[var(--ds-duration-fast)] md:max-w-sm"
      >
        <Search className="size-4 shrink-0" aria-hidden="true" />
        <span className="truncate">{t('actions.search')}</span>
        <kbd className="border-line bg-surface text-content-tertiary ms-auto hidden rounded border px-1.5 py-0.5 font-mono text-[0.6875rem] md:inline-block">
          ⌘K
        </kbd>
      </button>

      <div className="ms-auto flex items-center gap-1">
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
      </div>
    </header>
  );
}
