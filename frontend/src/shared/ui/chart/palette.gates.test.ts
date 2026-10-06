import { describe, expect, it } from 'vitest';
import tokens from '@/styles/tokens.css?raw';
import {
  contrastRatio,
  hexFromOklch,
  hueAngle,
  lightnessAndChroma,
  perceptualDistance,
} from './colour';

/*
|--------------------------------------------------------------------------
| Chart palette gates
|--------------------------------------------------------------------------
|
| Whether a palette is readable by a colour-blind reader is measurable, so it is a
| test rather than a review comment. These thresholds are the published ones, and
| they exist because the alternative — somebody looking at eight swatches and saying
| "those seem different enough" — is how a dashboard ends up with two series that a
| tenth of its male readers cannot tell apart.
|
| The tokens file is parsed rather than duplicated here. A palette asserted against a
| copy of itself is not a gate, and the CSS is what actually reaches the browser.
|
| If one of these fails after a palette change, the fix is to re-derive the palette
| (see docs/DATA_VISUALISATION.md), not to lower the number.
|
*/

/** Euclidean OKLab distance ×100 between two series that sit next to each other. */
const COLOUR_BLIND_GATE = 8;

/** The same distance under ordinary vision. A pair below this is hard for everyone. */
const NORMAL_VISION_GATE = 15;

/** A mark has to be visible against the surface it is drawn on. */
const CONTRAST_GATE = 3;

/** Below this, a hue stops doing identity work and reads as grey. */
const CHROMA_FLOOR = 0.1;

/** Lightness a mark has to sit inside to read as a mark on each surface. */
const BAND = {
  light: [0.43, 0.77],
  dark: [0.48, 0.67],
} as const;

/** The surfaces charts are actually drawn on — a card, not the page behind it. */
const SURFACE = {
  light: '#ffffff',
  dark: '#13151a',
} as const;

type Mode = keyof typeof BAND;

/**
 * The declarations inside one theme's block.
 *
 * The light values live in the `:root, [data-theme='light']` block and the dark ones
 * in `[data-theme='dark']`, so each is read from its own slice of the file.
 */
function block(mode: Mode): string {
  const marker = mode === 'dark' ? "[data-theme='dark']" : ':root,';
  const start = tokens.indexOf(marker);

  expect(start, `the ${mode} theme block is missing from tokens.css`).toBeGreaterThan(-1);

  const end = tokens.indexOf("\n[data-theme='dark']", start + 1);

  return mode === 'dark' ? tokens.slice(start) : tokens.slice(start, end === -1 ? undefined : end);
}

/**
 * One token's value as a hex string, following a single `var()` hop.
 *
 * Semantic tokens point at a ramp step (`--ds-success: var(--ds-success-600)`), and
 * the ramps are declared once for both themes, so a hop is followed against the whole
 * file rather than the theme's own block.
 */
function resolve(mode: Mode, name: string): string {
  const declaration = (scope: string, token: string): string | null =>
    new RegExp(`--ds-${token}:\\s*([^;]+);`).exec(scope)?.[1]?.trim() ?? null;

  let value = declaration(block(mode), name);

  expect(value, `--ds-${name} is not declared for the ${mode} theme`).not.toBeNull();

  const hop = /^var\(\s*--ds-([\w-]+)\s*\)$/.exec(value ?? '');

  if (hop !== null) {
    value = declaration(tokens, hop[1] ?? '');

    expect(value, `--ds-${name} points at --ds-${hop[1]}, which is not declared`).not.toBeNull();
  }

  const hex = hexFromOklch(value ?? '');

  expect(
    hex,
    `--ds-${name} resolves to "${value ?? ''}", which is not an oklch() value`,
  ).not.toBeNull();

  return hex ?? '';
}

const series = (mode: Mode): string[] =>
  [1, 2, 3, 4, 5, 6, 7, 8].map((slot) => resolve(mode, `chart-${slot}`));

const steps = (mode: Mode): string[] =>
  [1, 2, 3, 4, 5, 6].map((step) => resolve(mode, `chart-step-${step}`));

describe.each(['light', 'dark'] as const)('the %s categorical palette', (mode) => {
  it('keeps every series inside the lightness band for its surface', () => {
    const [low, high] = BAND[mode];

    for (const colour of series(mode)) {
      const { lightness } = lightnessAndChroma(colour);

      expect(lightness, `${colour} is lighter than L ${high}`).toBeLessThanOrEqual(high);
      expect(lightness, `${colour} is darker than L ${low}`).toBeGreaterThanOrEqual(low);
    }
  });

  it('keeps every series saturated enough to carry identity', () => {
    for (const colour of series(mode)) {
      expect(lightnessAndChroma(colour).chroma, `${colour} reads as grey`).toBeGreaterThanOrEqual(
        CHROMA_FLOOR,
      );
    }
  });

  it('separates series that sit next to each other, under colour blindness', () => {
    /*
     * Adjacent pairs, because the order of assignment is what decides which colours
     * ever touch: slots are handed out in sequence and never skipped, so slot 3 is
     * only ever beside slots 2 and 4.
     */
    const colours = series(mode);

    for (let index = 0; index + 1 < colours.length; index += 1) {
      const first = colours[index] ?? '';
      const second = colours[index + 1] ?? '';

      for (const kind of ['protan', 'deutan'] as const) {
        expect(
          perceptualDistance(first, second, kind),
          `${first} and ${second} collapse under ${kind}`,
        ).toBeGreaterThanOrEqual(COLOUR_BLIND_GATE);
      }
    }
  });

  it('separates series that sit next to each other, under ordinary vision', () => {
    const colours = series(mode);

    for (let index = 0; index + 1 < colours.length; index += 1) {
      const first = colours[index] ?? '';
      const second = colours[index + 1] ?? '';

      expect(
        perceptualDistance(first, second),
        `${first} and ${second} are hard to tell apart even with full colour vision`,
      ).toBeGreaterThanOrEqual(NORMAL_VISION_GATE);
    }
  });

  it('draws every series visibly against the surface it sits on', () => {
    for (const colour of series(mode)) {
      expect(
        contrastRatio(colour, SURFACE[mode]),
        `${colour} is too faint on ${SURFACE[mode]}`,
      ).toBeGreaterThanOrEqual(CONTRAST_GATE);
    }
  });

  it('holds four series apart in a chart where any two marks can touch', () => {
    /*
     * A scatter plot has no assignment order: any two points can land side by side,
     * so every pair has to clear the gate, not just the neighbours. Four is the
     * measured cap — which is why `seriesCap` exists and why a fifth series in a
     * scatter has to fold into "other" rather than take a ninth colour.
     */
    const colours = series(mode).slice(0, 4);

    for (let i = 0; i < colours.length; i += 1) {
      for (let j = i + 1; j < colours.length; j += 1) {
        const first = colours[i] ?? '';
        const second = colours[j] ?? '';

        for (const kind of ['protan', 'deutan'] as const) {
          expect(
            perceptualDistance(first, second, kind),
            `${first} and ${second} collapse under ${kind}`,
          ).toBeGreaterThanOrEqual(COLOUR_BLIND_GATE);
        }

        expect(perceptualDistance(first, second)).toBeGreaterThanOrEqual(NORMAL_VISION_GATE);
      }
    }
  });
});

describe.each(['light', 'dark'] as const)('the %s ordinal ramp', (mode) => {
  it('is one hue', () => {
    /*
     * The point of the ramp: a funnel's stages are stages of one thing. Eight hues
     * would say they are eight unrelated categories.
     */
    const hues = steps(mode).map(hueAngle);
    const spread = Math.max(...hues) - Math.min(...hues);

    expect(spread, 'the ordinal ramp has drifted off its hue').toBeLessThan(8);
  });

  it('steps far enough between stages to be seen as steps', () => {
    const lightnesses = steps(mode).map((colour) => lightnessAndChroma(colour).lightness);

    for (let index = 0; index + 1 < lightnesses.length; index += 1) {
      const gap = Math.abs((lightnesses[index + 1] ?? 0) - (lightnesses[index] ?? 0));

      expect(
        gap,
        `steps ${index + 1} and ${index + 2} are indistinguishable`,
      ).toBeGreaterThanOrEqual(0.06);
    }
  });

  it('keeps the step nearest the surface visible', () => {
    /*
     * A ramp is allowed to approach the surface — that is what "least" looks like —
     * but the smallest stage of a funnel still has to be a visible bar rather than
     * nothing at all. 2:1 rather than 3:1 because the stage is also labelled.
     */
    const ratios = steps(mode).map((colour) => contrastRatio(colour, SURFACE[mode]));

    expect(Math.min(...ratios)).toBeGreaterThanOrEqual(2);
  });

  it('runs in one direction', () => {
    const lightnesses = steps(mode).map((colour) => lightnessAndChroma(colour).lightness);
    const descending = [...lightnesses].sort((a, b) => b - a);
    const ascending = [...lightnesses].sort((a, b) => a - b);

    expect(
      lightnesses.join() === descending.join() || lightnesses.join() === ascending.join(),
      'the ordinal ramp is not monotone in lightness',
    ).toBe(true);
  });
});

describe('the diverging pair', () => {
  it.each(['light', 'dark'] as const)('reads as opposite with a neutral middle in %s', (mode) => {
    const low = resolve(mode, 'chart-pole-low');
    const high = resolve(mode, 'chart-pole-high');
    const middle = resolve(mode, 'chart-pole-mid');

    /*
     * The midpoint has to read as "nothing". A hue there would say the middle of the
     * scale is itself a state, which is the whole thing a diverging scale must not say.
     */
    expect(lightnessAndChroma(middle).chroma, 'the diverging midpoint is not neutral').toBeLessThan(
      0.03,
    );

    // And the poles have to be far enough apart to read as opposite rather than as two
    // shades of the same thing.
    expect(perceptualDistance(low, high)).toBeGreaterThanOrEqual(NORMAL_VISION_GATE);
  });
});

describe('status colours', () => {
  it.each(['light', 'dark'] as const)('stays legible as a mark in %s', (mode) => {
    /*
     * Status is a fixed scale with reserved meaning, never "series 9". It is always
     * shipped with an icon and a label, which is what lets a status colour sit below
     * the 4.5:1 text bar while the meaning still arrives.
     */
    for (const role of ['success', 'warning', 'danger', 'info']) {
      const colour = resolve(mode, role);

      expect(
        contrastRatio(colour, SURFACE[mode]),
        `--ds-${role} is too faint on ${SURFACE[mode]}`,
      ).toBeGreaterThanOrEqual(CONTRAST_GATE);
    }
  });
});
