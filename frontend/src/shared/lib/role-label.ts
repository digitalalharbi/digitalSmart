import type { TFunction } from 'i18next';

/**
 * A role's name in the reader's language.
 *
 * The server publishes a role's English name beside its slug. Printing that name in an Arabic interface leaves
 * English in the middle of an Arabic sentence, so the six system roles are named from the locale files by slug.
 * This is vocabulary only: the slug, the permission keys and the API contract are untouched, and a role a workspace
 * added itself (no entry here) keeps the name its owner gave it.
 */
export function roleLabel(t: TFunction, slug: string, serverName: string): string {
  return t(`administration.roles.${slug}`, { defaultValue: serverName });
}
