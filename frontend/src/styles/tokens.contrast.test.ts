import { describe, expect, it } from 'vitest';
import tokens from '@/styles/tokens.css?raw';
import { contrastRatio, hexFromOklch, linearFromHex } from '@/shared/ui/chart/colour';

/*
|--------------------------------------------------------------------------
| Text contrast gates
|--------------------------------------------------------------------------
|
| WCAG 2.2 AA requires 4.5:1 of normal-size text. That is measurable, so it is a test rather than a line in a
| design document — and the measurement found four real failures when it was first run: tertiary ink at 3.66:1
| against the sunken surface, amber at 3.07:1 against its own badge tint, and the info ink failing both the
| canvas and its tint. None of those are decoration: tertiary ink carries table headers, timestamps and the
| source labels this product's honesty rests on, and amber is the colour of a caveat.
|
| Every ink is measured against **every surface it can be read on**, not just the lightest. A token that passes
| on white and fails on the sunken panel it actually sits in has not passed.
|
| The CSS is parsed rather than copied here. A palette asserted against its own duplicate is not a gate, and
| this file is what reaches the browser.
|
| If one of these fails after a token change, the fix is to re-derive the step — search the ramp for the
| lightest one that clears the threshold on every surface — not to lower the number.
|
*/

/** WCAG 2.2 AA, normal-size text. Large text is allowed 3:1 and no ink here is large-only. */
const AA_TEXT = 4.5;

/** A focus ring or a border is a non-text element: AA asks 3:1 of those. */
const AA_NON_TEXT = 3;

type Mode = 'light' | 'dark';

function block(mode: Mode): string {
  const marker = mode === 'dark' ? "[data-theme='dark']" : ':root,';
  const start = tokens.indexOf(marker);

  expect(start, `the ${mode} theme block is missing from tokens.css`).toBeGreaterThan(-1);

  const end = tokens.indexOf("\n[data-theme='dark']", start + 1);

  return mode === 'dark' ? tokens.slice(start) : tokens.slice(start, end === -1 ? undefined : end);
}

function declaration(scope: string, token: string): string | null {
  return new RegExp(`--ds-${token}:\\s*([^;]+);`).exec(scope)?.[1]?.trim() ?? null;
}

/** A token's raw value, following a single `var()` hop into the ramps shared by both themes. */
function raw(mode: Mode, name: string): string {
  const value = declaration(block(mode), name);

  expect(value, `--ds-${name} is not declared for the ${mode} theme`).not.toBeNull();

  const hop = /^var\(\s*--ds-([\w-]+)\s*\)$/.exec(value ?? '');

  if (hop === null) {
    return value ?? '';
  }

  const hopped = declaration(tokens, hop[1] ?? '');

  expect(hopped, `--ds-${name} points at --ds-${hop[1]}, which is not declared`).not.toBeNull();

  return hopped ?? '';
}

/** Composite one colour over another, which is what the browser does with an alpha fill. */
function over(foreground: string, alpha: number, backdrop: string): string {
  const [fr, fg, fb] = linearFromHex(foreground);
  const [br, bg, bb] = linearFromHex(backdrop);

  const encode = (value: number): string => {
    const srgb = value <= 0.0031308 ? 12.92 * value : 1.055 * value ** (1 / 2.4) - 0.055;

    return Math.round(Math.min(1, Math.max(0, srgb)) * 255)
      .toString(16)
      .padStart(2, '0');
  };

  return `#${encode(fr * alpha + br * (1 - alpha))}${encode(fg * alpha + bg * (1 - alpha))}${encode(fb * alpha + bb * (1 - alpha))}`;
}

/**
 * A token as an opaque hex, with any alpha composited onto the surface it is painted over.
 *
 * The dark theme's status surfaces are the foreground hue at 16% — `oklch(... / 0.16)` — so reading them as
 * opaque would measure a colour that never appears on screen.
 */
function colour(mode: Mode, name: string, backdrop?: string): string {
  const value = raw(mode, name);
  const alpha = /\/\s*([\d.]+)\s*\)$/.exec(value)?.[1];
  const hex = hexFromOklch(value);

  expect(hex, `--ds-${name} resolves to "${value}", which is not an oklch() value`).not.toBeNull();

  if (alpha === undefined) {
    return hex ?? '';
  }

  expect(
    backdrop,
    `--ds-${name} is translucent, so it needs a backdrop to be measured on`,
  ).toBeDefined();

  return over(hex ?? '', Number.parseFloat(alpha), backdrop ?? '#ffffff');
}

/** The surfaces text is actually read on, worst case last. */
const SURFACES = ['surface', 'surface-raised', 'canvas', 'surface-sunken'] as const;

/** Inks that appear as ordinary text somewhere in the product. */
const INKS = [
  'text-primary',
  'text-secondary',
  'text-tertiary',
  'text-brand',
  'success',
  'warning',
  'danger',
  'info',
] as const;

describe.each(['light', 'dark'] as Mode[])('text contrast in the %s theme', (mode) => {
  it.each(INKS)('reads %s on every surface it can appear on', (ink) => {
    const foreground = colour(mode, ink);

    for (const surface of SURFACES) {
      const background = colour(mode, surface);
      const ratio = contrastRatio(foreground, background);

      expect(
        ratio,
        `--ds-${ink} on --ds-${surface} in ${mode} is ${ratio.toFixed(2)}:1 (${foreground} on ${background})`,
      ).toBeGreaterThanOrEqual(AA_TEXT);
    }
  });

  it.each(['success', 'warning', 'danger', 'info'] as const)(
    'reads %s on its own tinted surface',
    (status) => {
      /*
       * Where a status colour is most often read: a badge. The light theme tints with a solid ramp step and the
       * dark theme with the hue at 16% over the raised surface, so the dark value is composited before it is
       * measured — the opaque hue is a colour no reader ever sees.
       */
      const raised = colour(mode, 'surface-raised');
      const foreground = colour(mode, status, raised);
      const background = colour(mode, `${status}-surface`, raised);
      const ratio = contrastRatio(foreground, background);

      expect(
        ratio,
        `--ds-${status} on --ds-${status}-surface in ${mode} is ${ratio.toFixed(2)}:1`,
      ).toBeGreaterThanOrEqual(AA_TEXT);
    },
  );

  it('reads brand ink on the brand chip', () => {
    /*
     * The same shape as a status badge and easy to forget, because it is the one chip whose text token and
     * surface token are different brand steps rather than the same hue.
     */
    const raised = colour(mode, 'surface-raised');
    const ratio = contrastRatio(colour(mode, 'text-brand'), colour(mode, 'accent-subtle', raised));

    expect(
      ratio,
      `text-brand on accent-subtle in ${mode} is ${ratio.toFixed(2)}:1`,
    ).toBeGreaterThanOrEqual(AA_TEXT);
  });

  it('reads a button label on the accent it sits on', () => {
    const ratio = contrastRatio(colour(mode, 'accent-contrast'), colour(mode, 'accent'));

    // The one pair where both sides are brand colours, and the one a primary action depends on.
    expect(
      ratio,
      `accent-contrast on accent in ${mode} is ${ratio.toFixed(2)}:1`,
    ).toBeGreaterThanOrEqual(AA_TEXT);
  });

  it('shows a focus ring against every surface', () => {
    /*
     * Non-text, so 3:1 — and not optional. A keyboard user who cannot see where they are cannot use the
     * product at all, which is the failure no amount of colour elsewhere compensates for.
     */
    const ring = colour(mode, 'focus-ring');

    for (const surface of SURFACES) {
      const background = colour(mode, surface);
      const ratio = contrastRatio(ring, background);

      expect(
        ratio,
        `--ds-focus-ring on --ds-${surface} in ${mode} is ${ratio.toFixed(2)}:1`,
      ).toBeGreaterThanOrEqual(AA_NON_TEXT);
    }
  });
});
