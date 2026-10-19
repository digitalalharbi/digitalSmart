import { describe, expect, it } from 'vitest';
import type { TFunction } from 'i18next';
import { conditionParts, limitText } from './ruleText';

const t = ((key: string) => key.split('.').pop() ?? key) as unknown as TFunction;

describe('rule text', () => {
  it('says each limit the way a person would', () => {
    expect(limitText('spend', 500, 'SAR')).toBe('500 SAR');
    expect(limitText('roas', 3, null)).toBe('3.0×');
    expect(limitText('budget_used', 90, null)).toBe('90%');
    expect(limitText('campaign_age', 2, null)).toBe('2d');
  });

  it('keeps the comparison apart from the metric name, so Arabic is not scrambled by an LTR run', () => {
    expect(conditionParts(t, 'roas', 'below', 3)).toEqual({ name: 'roas', comparison: '< 3.0×' });
    expect(conditionParts(t, 'spend', undefined, 500, 'SAR')).toEqual({
      name: 'spend',
      comparison: '> 500 SAR',
    });
    // A state has no comparison at all.
    expect(conditionParts(t, 'not_delivering', undefined, null)).toEqual({
      name: 'not_delivering',
      comparison: null,
    });
  });
});
