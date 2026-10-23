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

/**
 * A token's colour as an opaque hex. The brand's own values are authored as hex or `rgb(r g b / a)` — exactly as the
 * brand kit spells them — and the product's older ramps as `oklch()`; all three are read here, and any alpha is left
 * for the caller to composite.
 */
function toHex(value: string): string | null {
  const hex = /^#([0-9a-f]{6})$/i.exec(value.trim());

  if (hex !== null) {
    return `#${(hex[1] ?? '').toLowerCase()}`;
  }

  const rgb = /^rgba?\(\s*(\d+)\s+(\d+)\s+(\d+)/i.exec(value.trim());

  if (rgb !== null) {
    return `#${[rgb[1], rgb[2], rgb[3]].map((channel) => Number(channel).toString(16).padStart(2, '0')).join('')}`;
  }

  return hexFromOklch(value);
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
  const hex = toHex(value);

  expect(
    hex,
    `--ds-${name} resolves to "${value}", which is not a hex, rgb() or oklch() value`,
  ).not.toBeNull();

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
/*
 * `workspace` replaced `canvas` here when the shell became a large rounded panel on a grey page: everything is read
 * on the panel, and the page behind it is only the margin around that panel on a wide screen. Below `lg` the panel
 * fills the viewport, so the margin is not on screen at all.
 */
const SURFACES = ['surface', 'surface-raised', 'workspace', 'surface-sunken'] as const;

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

  it.each(
    (['success', 'warning', 'danger', 'info'] as const).flatMap((status) =>
      (['surface-raised', 'surface', 'workspace'] as const).map(
        (under) => [status, under] as const,
      ),
    ),
  )('reads %s on its own tinted surface, over --ds-%s', (status, under) => {
    /*
     * Where a status colour is most often read: a badge. The light theme tints with a solid ramp step and the
     * dark theme with the hue at 16% over the raised surface, so the dark value is composited before it is
     * measured — the opaque hue is a colour no reader ever sees.
     */
    /*
     * Over every surface a card is painted on, not only the raised one: the badge in a table sits on `surface`,
     * which in the light theme is a step darker than white, and a tint over it is darker than the one over white.
     */
    const raised = colour(mode, under);
    const foreground = colour(mode, status, raised);
    const background = colour(mode, `${status}-surface`, raised);
    const ratio = contrastRatio(foreground, background);

    expect(
      ratio,
      `--ds-${status} on --ds-${status}-surface over --ds-${under} in ${mode} is ${ratio.toFixed(2)}:1`,
    ).toBeGreaterThanOrEqual(AA_TEXT);
  });

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

  it('reads a button label on the action it sits on', () => {
    const ratio = contrastRatio(colour(mode, 'action-contrast'), colour(mode, 'action'));

    // The pair a primary action and the active navigation pill depend on. Graphite, so it is not close.
    expect(
      ratio,
      `action-contrast on action in ${mode} is ${ratio.toFixed(2)}:1`,
    ).toBeGreaterThanOrEqual(AA_TEXT);
  });

  it('reads a button label on every state of the primary action', () => {
    /*
     * The brand's primary action is the orange, in four states. White on the kit's hover orange in the dark theme is
     * 3.5:1, so the label turns to the page black there; this holds the pair that results, and the pressed state,
     * which are the two a hand sees only for a moment and a test must therefore not forget.
     */
    for (const [label, fill] of [
      ['action-hover-contrast', 'action-hover'],
      ['action-contrast', 'action-pressed'],
      ['approve-contrast', 'approve'],
    ] as const) {
      const ratio = contrastRatio(colour(mode, label), colour(mode, fill));

      expect(
        ratio,
        `--ds-${label} on --ds-${fill} in ${mode} is ${ratio.toFixed(2)}:1`,
      ).toBeGreaterThanOrEqual(AA_TEXT);
    }
  });

  it('reads a small label on the strong accent, and the orange itself as a mark', () => {
    /*
     * The reference's orange is a fill, and white text on it is 3.5:1 — under AA for small text. Small labels
     * that must sit on orange use the strong step, which clears 4.5:1; the orange itself is held to the 3:1 a
     * non-text mark needs against the white card it is drawn on.
     */
    const strong = contrastRatio(colour(mode, 'accent-contrast'), colour(mode, 'accent-strong'));
    expect(
      strong,
      `accent-contrast on accent-strong in ${mode} is ${strong.toFixed(2)}:1`,
    ).toBeGreaterThanOrEqual(AA_TEXT);

    const mark = contrastRatio(colour(mode, 'accent'), colour(mode, 'surface'));
    expect(mark, `accent on surface in ${mode} is ${mark.toFixed(2)}:1`).toBeGreaterThanOrEqual(
      AA_NON_TEXT,
    );
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
