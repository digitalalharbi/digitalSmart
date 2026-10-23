import { describe, expect, it } from 'vitest';
import { isShown } from './visibility';
import { optionsToText, textToOptions } from './fieldOptions';

describe('isShown', () => {
  it('shows a field with no rule and follows every operator', () => {
    expect(isShown(null, {})).toBe(true);
    expect(isShown({ field: 'a', operator: 'equals', value: 'x' }, { a: 'x' })).toBe(true);
    expect(isShown({ field: 'a', operator: 'not_equals', value: 'x' }, { a: 'x' })).toBe(false);
    expect(isShown({ field: 'a', operator: 'in', value: 'x, y' }, { a: 'y' })).toBe(true);
    expect(isShown({ field: 'a', operator: 'filled', value: null }, { a: '' })).toBe(false);
  });
});

describe('options text', () => {
  it('round-trips value | Arabic | English and drops empty lines', () => {
    const options = textToOptions('villa | فيلا | Villa\n\nland | أرض\nflat');

    expect(options).toEqual([
      { value: 'villa', label_ar: 'فيلا', label_en: 'Villa' },
      { value: 'land', label_ar: 'أرض', label_en: null },
      { value: 'flat', label_ar: null, label_en: null },
    ]);
    expect(textToOptions(optionsToText(options))).toEqual(options);
  });
});
