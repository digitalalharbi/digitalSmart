import { describe, expect, it } from 'vitest';
import { isValidElement, type ReactElement } from 'react';
import type { RouteObject } from 'react-router';
import { NAV_ITEMS } from '@/app/navigation';
import { PlaceholderPage } from '@/features/placeholder';
import { routes } from './routes';

/**
 * Walks a route's element down through whatever wraps it.
 *
 * A deferred surface is a `<Suspense>` around a lazy component, so the element a route holds is not the page. The
 * test needs to know whether the page *is* the placeholder, which means looking inside.
 */
function elementsOf(element: ReactElement | undefined): unknown[] {
  if (element === undefined) {
    return [];
  }

  const props = element.props as { children?: unknown };
  const children = props.children;

  const nested = isValidElement(children) ? elementsOf(children) : [];

  return [element.type, ...nested];
}

describe('routes', () => {
  it('leaves no navigable surface as a placeholder', () => {
    /*
     * The invariant that replaced a single end-to-end example. Until Phase 24 one nav item was always still a
     * placeholder, and `shell.spec.ts` asserted the phase it named — a test that had to be moved four times as
     * surfaces landed, and that only ever checked one of them.
     *
     * Every item is now implemented, so the useful assertion is the exhaustive one: *no* nav path falls through
     * to `PlaceholderPage`. A new nav item added without a page fails here, which is the moment to notice it
     * rather than when somebody clicks it.
     */
    const shell = routes.find((route) => route.path === '/');
    expect(shell).toBeDefined();

    const children: RouteObject[] = shell?.children ?? [];
    const placeholders: string[] = [];

    for (const item of NAV_ITEMS) {
      if (item.path === '/') {
        continue;
      }

      const route = children.find((candidate) => candidate.path === item.path.replace(/^\//, ''));

      if (route === undefined) {
        placeholders.push(`${item.path} has no route at all`);

        continue;
      }

      const element = isValidElement(route.element) ? route.element : undefined;

      if (elementsOf(element).includes(PlaceholderPage)) {
        placeholders.push(`${item.path} is still a placeholder (${item.phase})`);
      }
    }

    expect(placeholders).toEqual([]);
  });

  it('keeps the placeholder available as the fallback it is', () => {
    /*
     * Deliberately not deleted. It is what a nav item gets when somebody adds one before its page exists, and an
     * honest "Planned for Phase N" is a better landing than a blank screen or a 404. The test above is what
     * makes sure it is never what ships.
     */
    expect(PlaceholderPage).toBeTypeOf('function');
  });
});
