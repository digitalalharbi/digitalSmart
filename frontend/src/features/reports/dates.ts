import { localeTag } from '@/shared/lib/format';

/** A calendar day, in the reader's language and Latin digits. */
export function dayOf(iso: string | null, locale: string): string {
  if (iso === null) {
    return '—';
  }

  return new Intl.DateTimeFormat(localeTag(locale), { dateStyle: 'medium' }).format(new Date(iso));
}
