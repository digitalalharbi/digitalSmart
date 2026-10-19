import { describe, expect, it } from 'vitest';
import { sumMinor } from './money';
import type { AllocationDraft } from './types';

function allocation(amount: string): AllocationDraft {
  return {
    key: amount,
    level: 'platform',
    provider: 'meta',
    label: 'Meta',
    amount,
    campaign_id: null,
    ad_group_id: null,
    children: [],
  };
}

describe('sumMinor', () => {
  it('adds in minor units so thirds do not drift', () => {
    /*
     * Three platforms at 33.33 come to exactly 99.99. Added as floats this is
     * 99.99000000000001, and a remainder of a thousandth of a halala renders as "over
     * budget" — a bug nobody can explain to a planner.
     */
    expect(sumMinor([allocation('33.33'), allocation('33.33'), allocation('33.33')], 'SAR')).toBe(
      9_999,
    );
  });

  it('scales by the budget’s currency rather than by a hundred', () => {
    /*
     * The same three strings are three different totals depending on the currency they
     * were typed in: a plan in dinars is counted in fils, and one in yen has no minor
     * unit to count at all. An allocation carries only the decimal a planner typed, so
     * the budget is what says which.
     */
    const plan = [allocation('1200'), allocation('800')];

    expect(sumMinor(plan, 'JPY')).toBe(2_000);
    expect(sumMinor(plan, 'SAR')).toBe(200_000);
    expect(sumMinor(plan, 'KWD')).toBe(2_000_000);
  });

  it('counts an unfinished entry as nothing rather than NaN', () => {
    expect(sumMinor([allocation('1000'), allocation('')], 'SAR')).toBe(100_000);
  });
});
