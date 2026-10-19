import { toMinor } from '@/shared/lib/money';
import type { AllocationDraft } from './types';

/*
 * Money arithmetic for the planner, kept out of the components.
 *
 * Minor units throughout, because the alternative is adding floats: a plan of three
 * platforms at 33.33 has to come to 99.99 and not 99.99000000000001, and a remainder of
 * a thousandth of a halala rendered as "over budget" is a bug nobody can explain.
 *
 * The conversion between a typed decimal and minor units is `toMinor`/`toDecimal` in
 * `@/shared/lib/money`, which scales by the currency rather than by a hundred. Only the
 * part that knows what an allocation is lives here.
 */

/**
 * What a set of allocations claims between them, in the budget's own minor units.
 *
 * The currency is a parameter because an allocation carries only the decimal string a
 * planner typed; the budget it belongs to is what says whether that string means halalas
 * or fils.
 */
export function sumMinor(allocations: AllocationDraft[], currency: string): number {
  return allocations.reduce((total, allocation) => total + toMinor(allocation.amount, currency), 0);
}
