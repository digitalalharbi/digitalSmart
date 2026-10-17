export type Money = { currency: string; minor: number };
export type Roas = { currency: string; value: number };

type HealthCounts = {
  healthy: number;
  watch: number;
  needs_attention: number;
  critical: number;
  inactive: number;
  insufficient_data: number;
};

type Figures = {
  spend: Money[];
  platform_revenue: Money[];
  roas: Roas[];
  active_campaigns: number;
  campaigns: number;
  campaign_health: HealthCounts;
  needs_attention: number;
};

export type PortfolioClient = Figures & {
  id: string;
  name: string;
  status: string;
  leads: number | null;
  qualified_leads: number | null;
  sales: number;
  budget_risks: number | null;
  tracking_attention: number | null;
  projects: number;
};

export type PortfolioProject = Figures & {
  id: string;
  client_id: string;
  /** Whose project it is. Null only if the client is not in the reader's roster. */
  client_name?: string | null;
  name: string;
  status: string;
  open_work: number;
};

export type Portfolio = {
  window: { from: string; to: string };
  kpis: Figures & {
    leads: number | null;
    qualified_leads: number | null;
    sales: number;
    store_revenue: Money[];
    active_clients: number;
    active_projects: number;
  };
  health: HealthCounts;
  clients: PortfolioClient[];
  projects: PortfolioProject[];
  platform_mix: Array<{ provider: string; spend: Money[] }>;
  /** Null means "not shown to your role", which is not the same as zero. */
  budget_health: { approved: number; at_risk: number } | null;
  tracking_health: { sources: number; needing_attention: number; critical: number } | null;
  assessed: number;
  total_campaigns: number;
  is_truncated: boolean;
};
