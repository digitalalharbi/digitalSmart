import type { AllocationDraft } from './types';

/**
 * Money arithmetic for the planner, kept out of the components.
 *
 * Minor units throughout, because the alternative is adding floats: a plan of three
 * platforms at 33.33 has to come to 99.99 and not 99.99000000000001, and a remainder of
 * a thousandth of a halala rendered as "over budget" is a bug nobody can explain.
 */

/**
 * A decimal string as minor units.
 *
 * An unparseable or empty value counts as nothing rather than NaN: a planner typing into
 * an empty amount field should see a remainder, not a screen of "NaN".
 */
export function toMinor(amount: string): number {
  const value = Number(amount);

  return Number.isFinite(value) ? Math.round(value * 100) : 0;
}

/** Minor units as the decimal string the API takes. */
export function toDecimal(minorUnits: number): string {
  return (minorUnits / 100).toFixed(2);
}

/** What a set of allocations claims between them. */
export function sumMinor(allocations: AllocationDraft[]): number {
  return allocations.reduce((total, allocation) => total + toMinor(allocation.amount), 0);
}
