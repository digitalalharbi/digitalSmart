/**
 * The attention queue, as the server publishes it.
 *
 * Snake_case on purpose: these are the wire shapes, and renaming them on arrival would mean every field
 * existing under two names with a mapping to keep honest between them.
 */

export type AttentionSeverityValue = 'critical' | 'needs_attention' | 'watch';

export type AttentionStreamValue =
  'campaigns' | 'budgets' | 'tracking' | 'approvals' | 'requests' | 'tasks';

export type AttentionItem = {
  id: string;
  stream: AttentionStreamValue;
  stream_label: string;
  severity: AttentionSeverityValue;
  severity_label: string;
  /** One sentence, already written by the module that found it. Never re-phrased here. */
  message: string;
  entity: { kind: string; label: string };
  /**
   * Who holds it.
   *
   * Three states, and the wire keeps them apart: a name; `null` for "nobody holds this", which is
   * printed because an unowned item is the one most likely to rot; and the key **absent** for a kind
   * that has no owner at all — a budget belongs to a client, not to a person.
   */
  owner?: string | null;
  since: string | null;
  client_id: string | null;
  client_name: string | null;
  project_id: string | null;
  project_name: string | null;
  action: { path: string };
};

export type AttentionStreamInfo = { stream: AttentionStreamValue; label: string };

export type AttentionQueue = {
  data: AttentionItem[];
  meta: {
    severity_counts: Record<AttentionSeverityValue, number>;
    total: number;
    /** The streams this reader may see, and which were consulted. */
    covered: AttentionStreamInfo[];
    /** Streams that were consulted and could not be read. */
    failed: AttentionStreamValue[];
    as_of: string;
    severities: Array<{ severity: AttentionSeverityValue; label: string; rank: number }>;
    streams: Array<{ stream: AttentionStreamValue; label: string; path: string }>;
  };
};
