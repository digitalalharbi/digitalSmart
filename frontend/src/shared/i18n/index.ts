import i18next from 'i18next';
import { initReactI18next } from 'react-i18next';
import en from './locales/en.json';
import ar from './locales/ar.json';

export const SUPPORTED_LOCALES = ['en', 'ar'] as const;
export type Locale = (typeof SUPPORTED_LOCALES)[number];

export const LOCALE_DIRECTION: Record<Locale, 'ltr' | 'rtl'> = {
  en: 'ltr',
  ar: 'rtl',
};

export function isLocale(value: string): value is Locale {
  return (SUPPORTED_LOCALES as readonly string[]).includes(value);
}

/** Apply direction + lang to <html>; CSS and Intl both key off these. */
export function applyDocumentLocale(locale: Locale): void {
  const root = document.documentElement;
  root.lang = locale;
  root.dir = LOCALE_DIRECTION[locale];
}

export async function initI18n(locale: Locale): Promise<void> {
  if (i18next.isInitialized) {
    await i18next.changeLanguage(locale);
    return;
  }

  await i18next.use(initReactI18next).init({
    resources: {
      en: { translation: en },
      ar: { translation: ar },
    },
    lng: locale,
    fallbackLng: 'en',
    supportedLngs: [...SUPPORTED_LOCALES],
    interpolation: { escapeValue: false },
    returnNull: false,
  });
}

export { i18next };
