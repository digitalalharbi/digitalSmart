import { describe, expect, it } from 'vitest';
import type { TFunction } from 'i18next';
import { actionText, conditionParts, limitText } from './ruleText';

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

  it('names a budget action recorded without its percentage instead of showing −0%', () => {
    const full = ((key: string) => key) as unknown as TFunction;
    expect(actionText(full, 'propose_budget_cut', null)).toBe(
      'automation.actPlain.propose_budget_cut',
    );
    expect(actionText(full, 'propose_budget_cut', 20)).toBe('automation.act.propose_budget_cut');
    expect(actionText(full, 'propose_pause', null)).toBe('automation.act.propose_pause');
  });
});
