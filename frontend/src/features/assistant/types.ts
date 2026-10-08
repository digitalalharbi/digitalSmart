/**
 * The assistant, as the API publishes it.
 *
 * Four shapes carry the module's argument rather than its data:
 *
 *  - **`narrative.written_by`** — the product or a named model. Both are legitimate; presenting one as the other
 *    is not.
 *  - **`narrative.model_rejected`** — a model answered and its answer was discarded for stating a figure the
 *    evidence does not contain. Published rather than swallowed: a guard nobody can see working is a guard
 *    nobody will maintain.
 *  - **`evidence` on every finding** — the metric, the figure, the period, the source and the surface it came
 *    from. Every number on screen traces to one.
 *  - **`skipped`** — the analysers this reader's permissions kept out of the answer. An answer that quietly
 *    differed by role would leave the reader with fewer permissions unaware a question had gone unasked.
 */

export type AssistantModeValue = 'ask' | 'analyse' | 'investigate' | 'report';

export type FindingSeverityValue = 'serious' | 'warning' | 'context' | 'insufficient';

export type ConfidenceValue = 'firm' | 'tentative' | 'indicative';

export type ModeInfo = {
  mode: AssistantModeValue;
  label: string;
  description: string;
  permission: string;
  /** False for every mode. The assistant proposes; a person decides, in the owning module. */
  enacts_anything: boolean;
};

export type DeferredInfo = {
  mode: string;
  label: string;
  because: string;
};

export type QuestionInfo = {
  intent: string;
  label: string;
  /** In the words somebody would actually type. A closed list is only usable if it is published. */
  example: string;
};

export type AssistantVocabulary = {
  modes: ModeInfo[];
  deferred: DeferredInfo[];
  questions: QuestionInfo[];
  severities: { severity: FindingSeverityValue; label: string; needs_attention: boolean }[];
  confidence: { confidence: ConfidenceValue; label: string; supports_recommendation: boolean }[];
  /** The denominator below which this product will not quote a rate. */
  denominator_floor: number;
};

export type EvidenceItem = {
  metric: string;
  label: string;
  value: number | null;
  compared_with: number | null;
  period: string;
  source: string;
  source_label: string;
  read_from: string;
  /** Why a figure is absent. A blank cell becomes a zero in the next calculation. */
  reason: string | null;
};

export type AssistantFinding = {
  code: string;
  severity: FindingSeverityValue;
  severity_label: string;
  needs_attention: boolean;
  subject: string;
  headline: string;
  detail: string;
  confidence: ConfidenceValue;
  confidence_label: string;
  source: string;
  source_label: string;
  /** Null unless the evidence was firm enough to act on. Most findings carry none. */
  recommendation: string | null;
  evidence: EvidenceItem[];
};

export type AssistantNarrative = {
  text: string;
  written_by: 'product' | 'model';
  model: string | null;
  model_rejected: boolean;
  rejection_reason: string | null;
};

export type AssistantAnswer = {
  mode: AssistantModeValue;
  client_name: string;
  period: { from: string; until: string; days: number };
  comparison_period: { from: string; until: string };
  question: string | null;
  /** What the question was taken to mean. Null when nothing matched, which is a refusal. */
  intent: string | null;
  source: string;
  source_label: string;
  requires_label: boolean;
  needs_attention: number;
  /** How many questions could not be answered from this period's data. */
  unanswered: number;
  skipped: string[];
  narrative: AssistantNarrative;
  findings: AssistantFinding[];
};

export type ReportProposal = {
  analysis: AssistantAnswer;
  /** Shaped as the reporting module's editor expects. Nothing has been saved. */
  proposal: {
    name: string;
    description: string | null;
    client_id: string | null;
    window: string;
    comparison: string;
    audience: string;
    sections: { kind: string; metrics: string[]; dimension: string | null }[];
  };
};
