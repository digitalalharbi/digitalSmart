import { expect, type Page } from '@playwright/test';

/**
 * How far a page reaches past the screen it is on. Zero in a direction means it does not.
 *
 * `sideways` is the document's own horizontal scroll. `down` is its vertical scroll. `clipped` is the furthest any
 * visible element is drawn below or beside the screen — the case a `overflow: hidden` page hides from the first two,
 * where content is lost rather than scrolled to.
 */
export type Reach = { sideways: number; down: number; clipped: number };

export async function reach(page: Page): Promise<Reach> {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const root = document.querySelector('#root > div') ?? document.body;
    let clipped = 0;

    for (const element of root.querySelectorAll('*')) {
      const box = element.getBoundingClientRect();

      if (box.width === 0 || box.height === 0 || getComputedStyle(element).display === 'none') {
        continue;
      }

      clipped = Math.max(clipped, box.bottom - window.innerHeight, box.right - window.innerWidth);
    }

    return {
      sideways: doc.scrollWidth - doc.clientWidth,
      down: doc.scrollHeight - window.innerHeight,
      clipped: Math.round(clipped),
    };
  });
}

/** The page does not scroll sideways. The one assertion every screen at every width owes. */
export async function expectNoSidewaysScroll(page: Page): Promise<void> {
  expect((await reach(page)).sideways, 'the page scrolls sideways').toBeLessThanOrEqual(0);
}

/** The page is one screen: it scrolls in neither direction and draws nothing off the screen. */
export async function expectOneScreen(page: Page): Promise<void> {
  const found = await reach(page);

  expect(found.sideways, 'sideways scroll').toBeLessThanOrEqual(0);
  expect(found.down, 'vertical scroll').toBeLessThanOrEqual(1);
  expect(found.clipped, 'something is drawn below or beside the screen').toBeLessThanOrEqual(1);
}
