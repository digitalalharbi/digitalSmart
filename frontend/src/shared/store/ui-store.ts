import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { applyDocumentLocale, isLocale, type Locale } from '@/shared/i18n';

export type ThemePreference = 'light' | 'dark' | 'system';
export type ResolvedTheme = 'light' | 'dark';

type UiState = {
  theme: ThemePreference;
  locale: Locale;
  /** Desktop: rail vs full sidebar. Persisted. */
  isSidebarCollapsed: boolean;
  /** Mobile: drawer visibility. Deliberately not persisted. */
  isMobileNavOpen: boolean;
  setTheme: (theme: ThemePreference) => void;
  setLocale: (locale: Locale) => void;
  toggleSidebar: () => void;
  setMobileNavOpen: (open: boolean) => void;
};

export function resolveTheme(preference: ThemePreference): ResolvedTheme {
  if (preference !== 'system') {
    return preference;
  }
  const prefersDark =
    typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches;
  return prefersDark ? 'dark' : 'light';
}

/** Theme is a document-level concern, so it is applied here rather than in a component. */
export function applyTheme(preference: ThemePreference): ResolvedTheme {
  const resolved = resolveTheme(preference);
  document.documentElement.dataset['theme'] = resolved;
  return resolved;
}

function detectInitialLocale(): Locale {
  const navigatorLocale = typeof navigator === 'undefined' ? 'en' : navigator.language.slice(0, 2);
  return isLocale(navigatorLocale) ? navigatorLocale : 'en';
}

export const useUiStore = create<UiState>()(
  persist(
    (set) => ({
      theme: 'system',
      locale: detectInitialLocale(),
      isSidebarCollapsed: false,
      isMobileNavOpen: false,
      setTheme: (theme) => {
        applyTheme(theme);
        set({ theme });
      },
      setLocale: (locale) => {
        applyDocumentLocale(locale);
        set({ locale });
      },
      toggleSidebar: () => set((state) => ({ isSidebarCollapsed: !state.isSidebarCollapsed })),
      setMobileNavOpen: (open) => set({ isMobileNavOpen: open }),
    }),
    {
      name: 'digitalsmart.ui',
      version: 1,
      partialize: (state) => ({
        theme: state.theme,
        locale: state.locale,
        isSidebarCollapsed: state.isSidebarCollapsed,
      }),
    },
  ),
);
