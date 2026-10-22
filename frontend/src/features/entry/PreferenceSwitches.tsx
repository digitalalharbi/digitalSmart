import { Languages, Moon, Sun } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { resolveTheme, useUiStore } from '@/shared/store/ui-store';
import { Button } from '@/shared/ui/button';
import type { Locale } from '@/shared/i18n';

/** Language and appearance, small and quiet — the two switches a visitor may need before signing in. */
export function PreferenceSwitches() {
  const { t } = useTranslation();
  const { theme, locale, setTheme, setLocale } = useUiStore();
  const resolved = resolveTheme(theme);
  const nextLocale: Locale = locale === 'en' ? 'ar' : 'en';

  return (
    <div className="flex items-center gap-0.5">
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
  );
}
