/**
 * Colour mathematics for charts.
 *
 * Two jobs, both of which have to happen in TypeScript rather than CSS.
 *
 * The first is conversion. The design tokens are authored in OKLCH because that is
 * the space in which "one step lighter" means the same thing for indigo as for gold.
 * A chart draws to a canvas, and ECharts does not only *pass* colours through — it
 * parses them to lift a hue on hover and to build an area gradient. Its parser
 * predates OKLCH and silently gives up on it, so every token is resolved to a plain
 * sRGB hex before it reaches a chart.
 *
 * The second is measurement. Whether a palette is safe for a colour-blind reader is
 * computable, and anything computable about a chart should be a test rather than an
 * opinion. These are the functions `palette.gates.test.ts` uses to hold the tokens to
 * the published thresholds.
 */

/** sRGB companding, from the sRGB specification. */
function toLinear(channel: number): number {
  return channel <= 0.04045 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4);
}

function fromLinear(channel: number): number {
  const clamped = Math.min(1, Math.max(0, channel));

  return clamped <= 0.0031308 ? 12.92 * clamped : 1.055 * Math.pow(clamped, 1 / 2.4) - 0.055;
}

export type Rgb = readonly [number, number, number];

/** Linear-light sRGB for a `#rrggbb` string. */
export function linearFromHex(hex: string): Rgb {
  const digits = hex.trim().replace(/^#/, '');
  const channel = (at: number): number =>
    toLinear(Number.parseInt(digits.slice(at, at + 2), 16) / 255);

  return [channel(0), channel(2), channel(4)];
}

function hexFromLinear([r, g, b]: Rgb): string {
  const byte = (channel: number): string =>
    Math.round(fromLinear(channel) * 255)
      .toString(16)
      .padStart(2, '0');

  return `#${byte(r)}${byte(g)}${byte(b)}`;
}

/** OKLab for a linear-light sRGB triple. */
function oklabFromLinear([r, g, b]: Rgb): Rgb {
  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);

  return [
    0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s,
    1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s,
    0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s,
  ];
}

/** Lightness and chroma, the two numbers the palette gates are expressed in. */
export function lightnessAndChroma(hex: string): { lightness: number; chroma: number } {
  const [lightness, a, b] = oklabFromLinear(linearFromHex(hex));

  return { lightness, chroma: Math.hypot(a, b) };
}

/** Hue angle in degrees, used to assert an ordinal ramp really is one hue. */
export function hueAngle(hex: string): number {
  const [, a, b] = oklabFromLinear(linearFromHex(hex));

  return ((((Math.atan2(b, a) * 180) / Math.PI) % 360) + 360) % 360;
}

/**
 * `oklch(L C H)` — and `oklch(L C H / A)` — as a hex string.
 *
 * Returns null for anything else, including a value a browser has already resolved
 * to `rgb(...)`: the caller then uses what it was given rather than guessing.
 */
export function hexFromOklch(value: string): string | null {
  const match =
    /^oklch\(\s*([\d.]+%?)\s+([\d.]+%?)\s+([\d.]+)(?:deg)?\s*(?:\/\s*[\d.%]+\s*)?\)$/i.exec(
      value.trim(),
    );

  if (match === null) {
    return null;
  }

  const number = (raw: string, percentOf: number): number =>
    raw.endsWith('%') ? (Number.parseFloat(raw) / 100) * percentOf : Number.parseFloat(raw);

  const lightness = number(match[1] ?? '0', 1);
  const chroma = number(match[2] ?? '0', 0.4);
  const hue = (Number.parseFloat(match[3] ?? '0') * Math.PI) / 180;

  const a = chroma * Math.cos(hue);
  const b = chroma * Math.sin(hue);

  const lCube = (lightness + 0.3963377774 * a + 0.2158037573 * b) ** 3;
  const mCube = (lightness - 0.1055613458 * a - 0.0638541728 * b) ** 3;
  const sCube = (lightness - 0.0894841775 * a - 1.291485548 * b) ** 3;

  return hexFromLinear([
    4.0767416621 * lCube - 3.3077115913 * mCube + 0.2309699292 * sCube,
    -1.2684380046 * lCube + 2.6097574011 * mCube - 0.3413193965 * sCube,
    -0.0041960863 * lCube - 0.7034186147 * mCube + 1.707614701 * sCube,
  ]);
}

/** WCAG relative luminance. */
function relativeLuminance(hex: string): number {
  const [r, g, b] = linearFromHex(hex);

  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG contrast ratio between two opaque colours. */
export function contrastRatio(a: string, b: string): number {
  const [high, low] = [relativeLuminance(a), relativeLuminance(b)].sort((x, y) => y - x) as [
    number,
    number,
  ];

  return (high + 0.05) / (low + 0.05);
}

/**
 * Machado, Oliveira & Fernandes (2009) colour-vision-deficiency transforms at full
 * severity, in linear light.
 *
 * The simulation model is part of the standard the thresholds were calibrated
 * against, not an implementation detail: swapping in another model moves every
 * borderline pair and would need the thresholds recalibrated with it.
 */
const DEFICIENCY = {
  protan: [
    [0.152286, 1.052583, -0.204868],
    [0.114503, 0.786281, 0.099216],
    [-0.003882, -0.048116, 1.051998],
  ],
  deutan: [
    [0.367322, 0.860646, -0.227968],
    [0.280085, 0.672501, 0.047413],
    [-0.01182, 0.04294, 0.968881],
  ],
  tritan: [
    [1.255528, -0.076749, -0.178779],
    [-0.078411, 0.930809, 0.147602],
    [0.004733, 0.691367, 0.3039],
  ],
} as const;

export type Deficiency = keyof typeof DEFICIENCY;

function simulate(hex: string, kind: Deficiency): Rgb {
  const linear = linearFromHex(hex);
  const matrix = DEFICIENCY[kind];
  const row = (index: 0 | 1 | 2): number => {
    const [x, y, z] = matrix[index] as [number, number, number];

    return Math.min(1, Math.max(0, x * linear[0] + y * linear[1] + z * linear[2]));
  };

  return [row(0), row(1), row(2)];
}

/**
 * Perceptual distance between two colours: Euclidean in OKLab, ×100.
 *
 * With no `kind`, under ordinary vision; with one, under that deficiency simulated
 * at full severity.
 */
export function perceptualDistance(a: string, b: string, kind?: Deficiency): number {
  const first = oklabFromLinear(kind === undefined ? linearFromHex(a) : simulate(a, kind));
  const second = oklabFromLinear(kind === undefined ? linearFromHex(b) : simulate(b, kind));

  return 100 * Math.hypot(first[0] - second[0], first[1] - second[1], first[2] - second[2]);
}
