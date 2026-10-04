import '@testing-library/jest-dom/vitest';
import { afterEach, beforeAll, vi } from 'vitest';
import { cleanup } from '@testing-library/react';

beforeAll(() => {
  // jsdom does not implement matchMedia, which the theme resolver relies on.
  if (!window.matchMedia) {
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: (query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
      }),
    });
  }
});

afterEach(() => {
  cleanup();
  localStorage.clear();
});
