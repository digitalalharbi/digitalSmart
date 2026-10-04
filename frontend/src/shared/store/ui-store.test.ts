import { beforeEach, describe, expect, it } from 'vitest';
import { applyTheme, resolveTheme, useUiStore } from './ui-store';

describe('ui store', () => {
  beforeEach(() => {
    useUiStore.setState({ theme: 'system', locale: 'en', isSidebarCollapsed: false });
    document.documentElement.removeAttribute('data-theme');
    document.documentElement.removeAttribute('dir');
  });

  it('resolves an explicit preference as-is', () => {
    expect(resolveTheme('dark')).toBe('dark');
    expect(resolveTheme('light')).toBe('light');
  });

  it('falls back to light when the OS does not prefer dark', () => {
    expect(resolveTheme('system')).toBe('light');
  });

  it('writes the resolved theme onto the document', () => {
    applyTheme('dark');
    expect(document.documentElement.dataset['theme']).toBe('dark');
  });

  it('sets document direction to rtl for Arabic', () => {
    useUiStore.getState().setLocale('ar');
    expect(document.documentElement.dir).toBe('rtl');
    expect(document.documentElement.lang).toBe('ar');
    expect(useUiStore.getState().locale).toBe('ar');
  });

  it('restores ltr when switching back to English', () => {
    useUiStore.getState().setLocale('ar');
    useUiStore.getState().setLocale('en');
    expect(document.documentElement.dir).toBe('ltr');
  });

  it('toggles the sidebar', () => {
    useUiStore.getState().toggleSidebar();
    expect(useUiStore.getState().isSidebarCollapsed).toBe(true);
    useUiStore.getState().toggleSidebar();
    expect(useUiStore.getState().isSidebarCollapsed).toBe(false);
  });
});
