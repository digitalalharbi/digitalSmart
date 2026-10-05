import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from '@/app/App';
import { applyDocumentLocale, i18next, initI18n } from '@/shared/i18n';
import { applyTheme, useUiStore } from '@/shared/store/ui-store';
/*
 * Self-hosted type. Inter is variable, so one file covers every weight; IBM
 * Plex Sans Arabic ships static weights, and only the four the interface uses
 * are pulled in. Loading these from JS means Vite fingerprints and preloads
 * them — a bare CSS @import of node_modules resolved to nothing.
 */
import '@fontsource-variable/inter';
import '@fontsource/ibm-plex-sans-arabic/400.css';
import '@fontsource/ibm-plex-sans-arabic/500.css';
import '@fontsource/ibm-plex-sans-arabic/600.css';
import '@fontsource/ibm-plex-sans-arabic/700.css';
import './index.css';

async function bootstrap(): Promise<void> {
  const { theme, locale } = useUiStore.getState();

  // Theme and direction are applied before the first paint to avoid a flash.
  applyTheme(theme);
  applyDocumentLocale(locale);
  await initI18n(locale);

  // Keep i18next in step with the store (e.g. the language toggle).
  useUiStore.subscribe((state, previous) => {
    if (state.locale !== previous.locale) {
      void i18next.changeLanguage(state.locale);
    }
  });

  const container = document.getElementById('root');
  if (!container) {
    throw new Error('Root container #root was not found in index.html.');
  }

  createRoot(container).render(
    <StrictMode>
      <App />
    </StrictMode>,
  );
}

void bootstrap();
