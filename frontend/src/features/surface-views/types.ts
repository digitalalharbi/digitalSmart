export type SurfaceKind = 'campaign_control' | 'budget_control' | 'leads' | 'ad_groups' | 'ads';

export type ViewVisibility = 'private' | 'project' | 'workspace';

/** A saved choice set, in the vocabulary of the page's own URL. */
export type SurfaceFilters = Record<string, string>;

export type SurfaceView = {
  id: string;
  surface: SurfaceKind;
  name: string;
  visibility: ViewVisibility;
  visibility_label: string;
  project_id: string | null;
  project_name: string | null;
  filters: Record<string, string | string[]>;
  author_name: string | null;
  is_mine: boolean;
};
