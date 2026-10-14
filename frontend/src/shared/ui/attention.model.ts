import { ageInDays } from '@/shared/lib/relative-time';
import { HEALTH_RANK, type HealthState } from '@/shared/ui/health.model';

/** Only the three states that are a call to act. Healthy, inactive and unknown never belong on this list. */
export type AttentionSeverity = Extract<HealthState, 'critical' | 'attention' | 'watch'>;

export interface AttentionItem {
  id: string;
  severity: AttentionSeverity;
  /** One sentence, in the language of the business. "Ramadan Push will overspend by Friday." */
  message: string;
  /** What it is about, named the way the product names it elsewhere. */
  entity: { kind: string; label: string };
  /**
   * Who holds it.
   *
   * Three states, and they are three different facts. A name. `null` for "nobody holds this", which is
   * printed rather than left blank, because an unowned item is the one most likely to rot. And absent for a
   * kind that has no owner at all — a budget is not assigned to a person, and printing *Unassigned* against
   * one would invent a gap that does not exist.
   */
  owner?: string | null;
  /** When it started being true, for age. `null` when the source cannot say. */
  since: string | null;
  /** Exactly one thing to do about it. */
  action: { label: string; to: string };
}

/**
 * Worst first, then oldest.
 *
 * Exported because callers that show a count ("4 need attention") must count the same set this renders, and
 * because the ordering is the part worth testing on its own.
 */
export function sortAttention(items: AttentionItem[]): AttentionItem[] {
  return [...items].sort((a, b) => {
    const bySeverity = HEALTH_RANK[a.severity] - HEALTH_RANK[b.severity];

    if (bySeverity !== 0) {
      return bySeverity;
    }

    /* An item with no date sorts last within its severity: unknown age is not old age. */
    const ageA = a.since === null ? -1 : (ageInDays(a.since) ?? -1);
    const ageB = b.since === null ? -1 : (ageInDays(b.since) ?? -1);

    return ageB - ageA;
  });
}
