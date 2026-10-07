import '@testing-library/jest-dom/vitest';
import { afterEach, beforeAll, vi } from 'vitest';
import { cleanup, configure } from '@testing-library/react';

/*
 * Five seconds for `findBy*` and `waitFor`, rather than the library's one.
 *
 * Every one of these waits is for a query to resolve and React to re-render — work that takes a few
 * milliseconds alone. The suite builds a jsdom per file and runs the files in parallel, so on a loaded
 * machine a single tick can cost most of a one-second budget, and the result is a test that passes on its
 * own and fails in the full run. That failure reads as a defect in whatever was touched last, which is the
 * most expensive kind to have.
 *
 * This raises a waiting budget, not an assertion: nothing here asserts how quickly anything renders, so a
 * longer ceiling cannot hide a regression. A query that never resolves still fails, five seconds later.
 */
configure({ asyncUtilTimeout: 5_000 });

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
