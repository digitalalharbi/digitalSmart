import type { FieldOption, FieldType } from './types';

export const FIELD_TYPES: FieldType[] = [
  'text',
  'email',
  'phone',
  'number',
  'textarea',
  'select',
  'radio',
  'checkbox',
  'date',
  'hidden',
  'consent',
];

/** Options are typed one per line as `value | Arabic label | English label`; the labels are optional. */
export function optionsToText(options: FieldOption[]): string {
  return options
    .map((o) => [o.value, o.label_ar ?? '', o.label_en ?? ''].join(' | ').replace(/( \| )+$/, ''))
    .join('\n');
}

export function textToOptions(text: string): FieldOption[] {
  return text
    .split('\n')
    .map((line) => line.split('|').map((part) => part.trim()))
    .filter((parts) => (parts[0] ?? '') !== '')
    .map(([value = '', ar, en]) => ({ value, label_ar: ar || null, label_en: en || null }));
}
