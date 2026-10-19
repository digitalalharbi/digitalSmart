import type { TFunction } from 'i18next';

export type Reason = { code: string; params: Record<string, string | number> } | null | undefined;

/**
 * A finding, in the reader's language.
 *
 * The server sends a code and its values (`CAMPAIGN_COST_RISE`, `{pct: 34}`); the sentence is written here. Where a
 * module has no code yet — or this build has never heard of one — the server's English sentence is the fallback,
 * so nothing ever renders as a key.
 */
export function reasonText(t: TFunction, reason: Reason, fallback: string): string {
  if (reason === null || reason === undefined) {
    return fallback;
  }

  return t(`reasons.${reason.code}`, { ...reason.params, defaultValue: fallback });
}
