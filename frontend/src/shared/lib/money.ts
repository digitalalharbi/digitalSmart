/**
 * How many minor units make a major one, and the arithmetic that depends on it.
 *
 * The browser's half of `App\Domain\Advertising\ValueObject\CurrencyExponent`. Money
 * crosses the wire as integer minor units with its currency code beside it, and the
 * exponent is the only thing that turns one into the other: 1200 is ¥1,200, or 1.200 KWD,
 * or SAR 12.00, and the distance between those readings is an invoice a client disputes.
 * A hard-coded hundred is right for most of the world and wrong by a factor of a hundred
 * for a yen and a thousand for a dinar.
 *
 * ## Why a table and not `Intl.NumberFormat`
 *
 * Intl does carry a fraction-digit count per currency, and for most currencies it is the
 * ISO exponent — so reading the scale off it and keeping no table at all looks like the
 * obvious way to stop a second copy drifting. It is the wrong source for the *scale*:
 *
 *  - **It is CLDR, not ISO 4217, and the two disagree for 18 of the 162 currencies this
 *    engine knows.** CLDR records how much precision a locale prints in cash prices,
 *    which for HUF, IDR, PKR, COP and twelve others is none at all, and for WST is two
 *    where ISO publishes none. The dangerous one is IQD: CLDR says none, ISO publishes
 *    three, and the server has stored fils accordingly — dividing by one there reports an
 *    Iraqi dinar budget a thousand times over.
 *  - **It moves with the engine's ICU version**, so the scale would depend on the
 *    reader's browser. A figure that is right in this month's Chrome and a hundredfold
 *    wrong in an older WebKit cannot be reconciled against anything.
 *
 * So the scale is this table, matching the server's, and Intl is left to do what it is
 * authoritative about: rendering the figure once it has been divided (`formatMinor` in
 * `./format`). `money.test.ts` reads the backend's table and asserts this one agrees with
 * it, so the two cannot drift apart quietly.
 *
 * The table lists only the currencies that are **not** two, because a list of every
 * two-decimal currency is a list nobody maintains and a default nobody reads. An absent
 * code is two — including a code this table has never heard of, which is the honest
 * answer for an unrecognised currency rather than a blank where a figure we could still
 * convert belongs.
 *
 * @see docs/MEASUREMENT.md for the server's half of the same rule
 * @see https://en.wikipedia.org/wiki/ISO_4217 for the published exponents
 */

/** The ISO 4217 default, used for every code the table below does not list. */
export const DEFAULT_CURRENCY_EXPONENT = 2;

const EXPONENTS: Readonly<Record<string, number>> = {
  // No minor unit: the amount is already in whole units.
  BIF: 0, // Burundian franc
  CLP: 0, // Chilean peso
  DJF: 0, // Djiboutian franc
  GNF: 0, // Guinean franc
  ISK: 0, // Icelandic króna
  JPY: 0, // Japanese yen
  KMF: 0, // Comorian franc
  KRW: 0, // South Korean won
  PYG: 0, // Paraguayan guaraní
  RWF: 0, // Rwandan franc
  UGX: 0, // Ugandan shilling
  UYI: 0, // Uruguayan peso en unidades indexadas
  VND: 0, // Vietnamese đồng
  VUV: 0, // Vanuatu vatu
  WST: 0, // Samoan tālā
  XAF: 0, // Central African CFA franc
  XOF: 0, // West African CFA franc
  XPF: 0, // CFP franc

  // Thousandths: the Gulf and North Africa.
  BHD: 3, // Bahraini dinar
  IQD: 3, // Iraqi dinar
  JOD: 3, // Jordanian dinar
  KWD: 3, // Kuwaiti dinar
  LYD: 3, // Libyan dinar
  OMR: 3, // Omani rial
  TND: 3, // Tunisian dinar

  // The two four-decimal units ISO publishes, both inflation-indexed accounting units.
  CLF: 4, // Unidad de Fomento
  UYW: 4, // Unidad previsional
};

/**
 * The exponent for a currency code, defaulting to two for anything unlisted.
 *
 * Accepts the code in any case and with surrounding space, because it arrives from a
 * provider's JSON rather than from us, and accepts no currency at all: a figure whose
 * currency the API withheld still has to render, and two is what the server assumed when
 * it stored the figure.
 */
export function currencyExponent(currency: string | null | undefined): number {
  if (currency === null || currency === undefined) {
    return DEFAULT_CURRENCY_EXPONENT;
  }

  return EXPONENTS[currency.trim().toUpperCase()] ?? DEFAULT_CURRENCY_EXPONENT;
}

/** The divisor between minor and major units: 100 for SAR, 1 for JPY, 1,000 for KWD. */
export function minorUnitsPerMajor(currency: string | null | undefined): number {
  return 10 ** currencyExponent(currency);
}

/** Minor units as major units, for arithmetic and for plotting. Divides exactly once. */
export function fromMinor(minor: number, currency: string | null | undefined): number {
  return minor / minorUnitsPerMajor(currency);
}

/**
 * A typed amount as minor units, rounded at the currency's own minor unit.
 *
 * An unparseable or empty value counts as nothing rather than NaN: somebody typing into
 * an empty amount field should see a remainder, not a screen of "NaN".
 */
export function toMinor(amount: string | number, currency: string | null | undefined): number {
  const value = typeof amount === 'number' ? amount : Number(amount);

  return Number.isFinite(value) ? Math.round(value * minorUnitsPerMajor(currency)) : 0;
}

/**
 * Minor units as the decimal string the API takes.
 *
 * To the currency's own precision: "1200" for a yen amount, "1.234" for a dinar one.
 * Padding a yen with two zeroes would be a decimal the currency does not have, and
 * truncating a dinar to two would drop a fils per row.
 */
export function toDecimal(minor: number, currency: string | null | undefined): string {
  return fromMinor(minor, currency).toFixed(currencyExponent(currency));
}
