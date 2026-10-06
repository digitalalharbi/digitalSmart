import { describe, expect, it } from 'vitest';
import { sumMinor, toDecimal, toMinor } from './money';
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

describe('money', () => {
  it('reads a decimal string as minor units', () => {
    expect(toMinor('1500.50')).toBe(150_050);
    expect(toMinor('0.01')).toBe(1);
  });

  it('treats an unfinished entry as nothing rather than NaN', () => {
    /*
     * A planner typing into an empty amount field should see a remainder, not a screen
     * reading "NaN over the budget".
     */
    expect(toMinor('')).toBe(0);
    expect(toMinor('abc')).toBe(0);
    expect(toMinor('.')).toBe(0);
  });

  it('rounds at the minor unit rather than drifting', () => {
    // 33.335 is a typo; the nearest halala is the kinder reading of it.
    expect(toMinor('33.335')).toBe(3_334);
  });

  it('adds in minor units so thirds do not drift', () => {
    /*
     * Three platforms at 33.33 come to exactly 99.99. Added as floats this is
     * 99.99000000000001, and a remainder of a thousandth of a halala renders as "over
     * budget" — a bug nobody can explain to a planner.
     */
    expect(sumMinor([allocation('33.33'), allocation('33.33'), allocation('33.33')])).toBe(9_999);
  });

  it('round-trips through the decimal string the API takes', () => {
    expect(toDecimal(toMinor('180000.00'))).toBe('180000.00');
    expect(toDecimal(1)).toBe('0.01');
  });
});
