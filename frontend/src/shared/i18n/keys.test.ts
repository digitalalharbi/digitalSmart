import { describe, expect, it } from 'vitest';
import ar from './locales/ar.json';
import en from './locales/en.json';

/*
 * The gate that was missing.
 *
 * A key with no entry does not throw and does not fail a build: i18next renders the key path, so the page says
 * `commerce.byChannel` where it should say "By channel". That is exactly what had happened — twice on the client
 * portal's revenue panel, which is the one surface an agency's client reads, and once on the integrations page.
 * Nothing caught it because nothing was looking.
 *
 * Two directions are checked, and they are different failures:
 *
 *  - **A `t('…')` call with no English entry** renders the key path to a reader. This is the one that was broken.
 *  - **An English key with no Arabic entry** falls back to English, which is quieter and worse: the page still
 *    reads, in the wrong language, inside an interface that is otherwise right to left.
 *
 * Arabic's plural forms are the reason this cannot be a naive set comparison. CLDR gives Arabic six categories
 * and English two, so `clients.count` legitimately exists as `_one`/`_other` in English and as six suffixes in
 * Arabic — and a key used as `t('clients.count', { count })` matches none of them literally.
 */

type Tree = { [key: string]: string | Tree };

/** Every leaf path in a locale file. */
function flatten(tree: Tree, prefix = ''): Set<string> {
  const keys = new Set<string>();

  for (const [key, value] of Object.entries(tree)) {
    const path = `${prefix}${key}`;

    if (typeof value === 'string') {
      keys.add(path);
    } else {
      for (const nested of flatten(value, `${path}.`)) {
        keys.add(nested);
      }
    }
  }

  return keys;
}

/** The CLDR plural suffixes i18next appends. A key is satisfied by any one of them. */
const PLURAL_SUFFIXES = ['_zero', '_one', '_two', '_few', '_many', '_other'];

function isSatisfied(key: string, available: Set<string>): boolean {
  return available.has(key) || PLURAL_SUFFIXES.some((suffix) => available.has(`${key}${suffix}`));
}

/**
 * Every source file under `src`, as text.
 *
 * Vite's own glob rather than `node:fs`, because the app's TypeScript project deliberately does not include
 * node's types — a test that imported them would be the only file in `src` that could touch the filesystem, and
 * the first place somebody later did something that cannot run in a browser.
 */
const SOURCES: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
});

function sourceFiles(): string[] {
  return Object.keys(SOURCES).filter((path) => !/\.test\.tsx?$/.test(path));
}

/**
 * Keys passed to `t()` as a plain string literal.
 *
 * Template literals are deliberately out of scope: `t(`leads.stages.${stage}`)` cannot be resolved without
 * knowing the union, and a check that guessed would either miss keys or invent them. Those paths are covered by
 * the component tests that render every member.
 */
function literalKeys(): Map<string, string[]> {
  const keys = new Map<string, string[]>();

  for (const file of sourceFiles()) {
    const source = SOURCES[file] ?? '';

    for (const match of source.matchAll(/\bt\(\s*'([a-zA-Z0-9_.]+)'/g)) {
      const key = match[1];

      if (key === undefined) {
        continue;
      }

      keys.set(key, [...(keys.get(key) ?? []), file]);
    }
  }

  return keys;
}

describe('every key a surface asks for', () => {
  it('exists in English, or the page renders the key path instead of a sentence', () => {
    const available = flatten(en);
    const missing = [...literalKeys().entries()]
      .filter(([key]) => !isSatisfied(key, available))
      .map(([key, files]) => `${key} (${files.join(', ')})`);

    expect(missing).toEqual([]);
  });

  it('exists in Arabic, or the page reads in English inside a right-to-left interface', () => {
    const available = flatten(ar);
    const missing = [...literalKeys().entries()]
      .filter(([key]) => !isSatisfied(key, available))
      .map(([key]) => key);

    expect(missing).toEqual([]);
  });
});

describe('the two locale files', () => {
  /*
   * English is the contract. An Arabic key with no English counterpart is either a typo nobody will see or copy
   * that was removed on one side only, and both end as dead weight somebody later translates again.
   */
  it('agree on which keys exist, allowing for Arabic’s plural forms', () => {
    const english = flatten(en);
    const arabic = flatten(ar);

    const base = (key: string): string => {
      const suffix = PLURAL_SUFFIXES.find((candidate) => key.endsWith(candidate));

      return suffix === undefined ? key : key.slice(0, -suffix.length);
    };

    const englishBases = new Set([...english].map(base));
    const arabicBases = new Set([...arabic].map(base));

    expect([...englishBases].filter((key) => !arabicBases.has(key))).toEqual([]);
    expect([...arabicBases].filter((key) => !englishBases.has(key))).toEqual([]);
  });
});

/*
 * Arabic has six plural categories. i18next looks up exactly the category the count falls in and does not fall
 * back to `_other`, so a group with only `_one` and `_other` renders in English for 0, 2 and 3–10 — the counts
 * that are most of what a reader sees. Found on the commerce page, where "2 orders are placed…" appeared in
 * English inside an Arabic interface.
 */
describe('Arabic plural groups', () => {
  it('carry all six categories wherever they carry any', () => {
    const missing: string[] = [];
    const walk = (tree: Tree, prefix: string) => {
      const keys = new Set(Object.keys(tree));
      for (const key of keys) {
        const value = tree[key];
        if (typeof value !== 'string' && value !== undefined) {
          walk(value, `${prefix}${key}.`);
        } else if (key.endsWith('_other')) {
          const base = key.slice(0, -'_other'.length);
          for (const category of ['zero', 'one', 'two', 'few', 'many']) {
            if (!keys.has(`${base}_${category}`)) {
              missing.push(`${prefix}${base}_${category}`);
            }
          }
        }
      }
    };
    walk(ar, '');

    expect(missing).toEqual([]);
  });
});
