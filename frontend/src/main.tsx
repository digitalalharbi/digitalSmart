import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from '@/app/App';
import { applyDocumentLocale, i18next, initI18n } from '@/shared/i18n';
import { applyTheme, useUiStore } from '@/shared/store/ui-store';
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
