import { describe, expect, it } from 'vitest';
import { NAV_ICONS } from '@/app/layout/navIcons';
import { NAV_ITEMS } from './navigation';

describe('navigation', () => {
  it('has an icon registered for every item', () => {
    /*
     * An unregistered icon name falls back to the overview icon without complaint, which is
     * how three separate surfaces came to be indistinguishable in the sidebar. Silence is the
     * failure mode, so this is the test that breaks it.
     */
    const missing = NAV_ITEMS.filter((item) => NAV_ICONS[item.icon] === undefined).map(
      (item) => `${item.path} wants ${item.icon}`,
    );

    expect(missing).toEqual([]);
  });

  it('gives every surface its own icon', () => {
    const icons = NAV_ITEMS.map((item) => item.icon);

    // Two sidebar entries wearing one icon is a sidebar a reader has to read rather than scan.
    expect(new Set(icons).size).toBe(icons.length);
  });

  it('has no duplicate paths', () => {
    const paths = NAV_ITEMS.map((item) => item.path);

    expect(new Set(paths).size).toBe(paths.length);
  });
});
