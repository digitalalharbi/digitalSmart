import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { applyDocumentLocale, isLocale, type Locale } from '@/shared/i18n';

export type ThemePreference = 'light' | 'dark' | 'system';

/**
 * Whether charts separate their series by pattern as well as by colour.
 *
 * `auto` means the chart decides from the environment — forced colours, or a print in progress —
 * which covers the cases a reader cannot be expected to configure. `always` is a reader saying they
 * need it regardless, which no media query can detect: full colour blindness does not announce
 * itself to a browser, and neither does a report that is about to be photocopied.
 */
export type ChartTexturePreference = 'auto' | 'always';
export type ResolvedTheme = 'light' | 'dark';

/**
 * The agency's working context: everything, one client, or one project.
 *
 * Held in memory and **not** persisted. A narrowing that survives a reload is one somebody forgets they set,
 * and every surface then quietly shows a fraction of the agency under its normal title. It survives
 * navigation — which is the point — and the chip that shows it is always on screen.
 */
export type WorkContext = {
  client: { id: string; label: string } | null;
  project: { id: string; label: string } | null;
};

/** The reading window every analytical surface shares: one choice, made once, read the same everywhere. */
export type PeriodDays = 7 | 30 | 90;
export type PeriodCompare = 'previous_period' | 'previous_year' | 'none';
export type Period = { days: PeriodDays; compare: PeriodCompare };

type UiState = {
  context: WorkContext;
  period: Period;
  setPeriod: (period: Period) => void;
  setContext: (context: WorkContext) => void;
  isPaletteOpen: boolean;
  setPaletteOpen: (open: boolean) => void;
  theme: ThemePreference;
  locale: Locale;
  /** Desktop: rail vs full sidebar. Persisted. */
  isSidebarCollapsed: boolean;
  /** Mobile: drawer visibility. Deliberately not persisted. */
  isMobileNavOpen: boolean;
  chartTexture: ChartTexturePreference;
  setTheme: (theme: ThemePreference) => void;
  setLocale: (locale: Locale) => void;
  toggleSidebar: () => void;
  setMobileNavOpen: (open: boolean) => void;
  setChartTexture: (preference: ChartTexturePreference) => void;
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
      context: { client: null, project: null },
      setContext: (context) => set({ context }),
      period: { days: 30, compare: 'previous_period' },
      setPeriod: (period) => set({ period }),
      isPaletteOpen: false,
      setPaletteOpen: (open) => set({ isPaletteOpen: open }),
      theme: 'system',
      locale: detectInitialLocale(),
      isSidebarCollapsed: false,
      isMobileNavOpen: false,
      chartTexture: 'auto',
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
      setChartTexture: (preference) => set({ chartTexture: preference }),
    }),
    {
      name: 'digitalsmart.ui',
      version: 1,
      partialize: (state) => ({
        theme: state.theme,
        locale: state.locale,
        isSidebarCollapsed: state.isSidebarCollapsed,
        /*
         * Persisted. Somebody who needs patterns needs them on every page and on every visit, and
         * asking again tomorrow would be asking them to tell us about their eyesight daily.
         */
        chartTexture: state.chartTexture,
        /* Persisted: a reader who looks at a month looks at a month tomorrow too. Shown on every page that uses it. */
        period: state.period,
      }),
    },
  ),
);
