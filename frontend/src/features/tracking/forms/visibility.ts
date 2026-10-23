import type { Visibility } from './types';

/**
 * Whether a field is shown. The same rule, with the same operators, as the server's `FieldVisibility`: the page never
 * offers a field the server would refuse, and the server never requires one the visitor could not see.
 */
export function isShown(rule: Visibility | null, answers: Record<string, string>): boolean {
  if (rule === null) {
    return true;
  }

  const actual = (answers[rule.field] ?? '').trim();
  const expected = rule.value ?? '';

  switch (rule.operator) {
    case 'equals':
      return actual === expected;
    case 'not_equals':
      return actual !== expected;
    case 'in':
      return expected.split(',').some((v) => v.trim() === actual);
    case 'filled':
      return actual !== '' && actual !== '0';
    default:
      return false;
  }
}
