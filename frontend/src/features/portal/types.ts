import type { MetricValue } from '@/shared/metrics';
import type { RenderedReport, ReportSummary } from '@/features/reports';

/**
 * The client portal, as the API publishes it.
 *
 * Three shapes carry the module's argument rather than its data:
 *
 *  - **Three lists, not one.** `modules` is what can be exposed, `withheld` is what never will be with the reason
 *    for each, and `always_available` is what cannot be switched off. A configuration screen showing only the
 *    first makes every refusal look like a feature nobody got round to.
 *  - **`is_prerequisite_met`** — whether a module would show the client anything. Exposing an empty surface reads
 *    to a client as the agency having done nothing, so the screen says so before the switch is thrown.
 *  - **`is_preview`** on every client-side read. An agency member who mistook a preview for their own view of the
 *    product would conclude half of it had disappeared.
 */

export type PortalModuleValue = 'performance' | 'reports' | 'store_revenue' | 'tracking';

export type PortalStateValue = 'not_configured' | 'active' | 'suspended';

export type PortalModuleInfo = {
  module: PortalModuleValue;
  label: string;
  description: string;
  /** What must exist in the workspace before the module can show anything. Null when nothing must. */
  prerequisite: string | null;
};

export type WithheldModuleInfo = {
  module: string;
  label: string;
  because: string;
};

export type PortalVocabulary = {
  modules: PortalModuleInfo[];
  always_available: WithheldModuleInfo[];
  withheld: WithheldModuleInfo[];
};

export type PortalSummary = {
  client_id: string;
  client_name: string;
  state: PortalStateValue;
  state_label: string;
  exposed_count: number;
  /** Of those, how many can show nothing yet. The figure that embarrasses somebody. */
  empty_count: number;
};

export type PortalModuleExposure = {
  module: PortalModuleValue;
  label: string;
  description: string;
  is_exposed: boolean;
  exposed_at: string | null;
  exposed_by_name: string | null;
  prerequisite: string | null;
  is_prerequisite_met: boolean;
};

export type PortalDetail = {
  client_id: string;
  client_name: string;
  state: PortalStateValue;
  state_label: string;
  enabled_at: string | null;
  enabled_by_name: string | null;
  suspended_at: string | null;
  suspended_by_name: string | null;
  /** The agency's own note. On the agency's surface only; the client is never shown it. */
  suspended_reason: string | null;
  modules: PortalModuleExposure[];
  empty_count: number;
};

/** What a reader of the portal may open. */
export type PortalEntry = {
  client_id: string;
  client_name: string;
  state: PortalStateValue;
  /** The sentence shown instead of a surface. Null when the portal is open. */
  message: string | null;
  modules: { module: PortalModuleValue; label: string }[];
  always_available: { module: string; label: string }[];
  is_preview: boolean;
};

export type PortalPeriod = {
  from: string;
  until: string;
  days: number;
};

export type PortalPerformance = {
  is_preview: boolean;
  client_name: string;
  period: PortalPeriod;
  /** Named, because a change figure with no stated baseline is one somebody misreads. */
  comparison_period: { from: string; until: string };
  source: string;
  source_label: string;
  requires_label: boolean;
  currency: string | null;
  is_empty: boolean;
  totals: Record<string, MetricValue>;
  series: { date: string; source: string; values: Record<string, MetricValue> }[];
};

export type { RenderedReport, ReportSummary };
