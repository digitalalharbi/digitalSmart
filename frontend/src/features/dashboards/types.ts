import type { AnalyticsQuery, ComparisonMode, DatePreset, DimensionValue } from '@/shared/metrics';

/** How a widget renders what it asked for. Mirrors the backend's WidgetType. */
export type WidgetTypeValue = 'scorecard' | 'line' | 'bar' | 'share' | 'scatter' | 'table';

export type VisibilityValue = 'private' | 'workspace' | 'project';

export type WidgetPayload = {
  id: string;
  title: string | null;
  type: WidgetTypeValue;
  type_label: string;
  metrics: string[];
  dimension: DimensionValue | null;
  /**
   * Null means "inherit the dashboard's filters"; `{}` means "override with
   * nothing". Two different instructions — collapsing them changes what the widget
   * shows, so the null is preserved all the way through.
   */
  filter_overrides: Partial<AnalyticsQuery> | null;
  column: number;
  row: number;
  width: number;
  height: number;
  options: Record<string, unknown>;
};

export type DashboardFilters = {
  preset?: DatePreset;
  start?: string;
  end?: string;
  compare?: ComparisonMode;
  providers?: string[];
  project_ids?: string[];
  client_id?: string | null;
  account_ids?: string[];
  campaign_ids?: string[];
};

export type DashboardPayload = {
  id: string;
  name: string;
  description: string | null;
  visibility: VisibilityValue;
  visibility_label: string;
  filters: DashboardFilters;
  is_default: boolean;
  project_id: string | null;
  project_name: string | null;
  author_name: string | null;
  /** Whether this actor may edit or delete it. The server refuses either way. */
  can_manage: boolean;
  /** The grid the server validates against, so the client lays out on the same one. */
  grid_columns: number;
  widgets: WidgetPayload[];
  updated_at: string;
};

/** A widget being edited, before it has been saved and given an id. */
export type WidgetDraft = Omit<WidgetPayload, 'id' | 'type_label'> & {
  /** Stable only within the editing session; the server reassigns ids on save. */
  key: string;
};

export type DashboardDraft = {
  name: string;
  description: string | null;
  visibility: VisibilityValue;
  project_id: string | null;
  filters: DashboardFilters;
  is_default: boolean;
  widgets: WidgetDraft[];
};

export type SavedViewPayload = {
  id: string;
  name: string;
  description: string | null;
  visibility: VisibilityValue;
  visibility_label: string;
  query: Partial<AnalyticsQuery> & { start?: string; end?: string };
  is_default: boolean;
  project_id: string | null;
  project_name: string | null;
  author_name: string | null;
  can_manage: boolean;
  last_used_at: string | null;
  updated_at: string;
};

export type SavedViewInput = {
  name: string;
  description?: string | null;
  visibility?: VisibilityValue;
  project_id?: string | null;
  is_default?: boolean;
  query: Partial<AnalyticsQuery>;
};
