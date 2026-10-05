/**
 * Single source of truth for primary navigation.
 * `phase` records which roadmap phase delivers the surface, so placeholders stay honest.
 */
export type NavSection = 'work' | 'intelligence' | 'system';

export type NavItem = {
  path: string;
  labelKey: string;
  icon: string;
  section: NavSection;
  phase: string;
  /**
   * Permission that reveals this item. Undefined means every member sees it.
   * Hiding is a courtesy for a cleaner interface; the server enforces access
   * on every request regardless.
   */
  permission?: string;
};

export const NAV_ITEMS: readonly NavItem[] = [
  {
    path: '/',
    labelKey: 'nav.overview',
    icon: 'LayoutDashboard',
    section: 'work',
    phase: 'Phase 0',
  },
  {
    path: '/projects',
    labelKey: 'nav.projects',
    icon: 'FolderKanban',
    section: 'work',
    phase: 'Phase 1',
    permission: 'projects.view_assigned',
  },
  {
    path: '/clients',
    labelKey: 'nav.clients',
    icon: 'Building2',
    section: 'work',
    phase: 'Phase 1',
    permission: 'clients.view',
  },
  {
    path: '/campaigns',
    labelKey: 'nav.campaigns',
    icon: 'Megaphone',
    section: 'work',
    phase: 'Phase 3',
    // The surface reads performance data, so it is gated on the permission the
    // endpoint actually checks. Offering it to a role that would receive a 403 is
    // worse than not offering it.
    permission: 'analytics.view',
  },
  {
    path: '/creative-hub',
    labelKey: 'nav.creativeHub',
    icon: 'Palette',
    section: 'work',
    phase: 'Phase 6',
  },
  { path: '/tasks', labelKey: 'nav.tasks', icon: 'ListChecks', section: 'work', phase: 'Phase 6' },
  {
    path: '/approvals',
    labelKey: 'nav.approvals',
    icon: 'BadgeCheck',
    section: 'work',
    phase: 'Phase 6',
  },
  {
    path: '/analytics',
    labelKey: 'nav.analytics',
    icon: 'ChartNoAxesCombined',
    section: 'intelligence',
    phase: 'Phase 4',
    permission: 'analytics.view',
  },
  {
    path: '/dashboards',
    labelKey: 'nav.dashboards',
    icon: 'LayoutGrid',
    section: 'intelligence',
    phase: 'Phase 4',
    permission: 'analytics.view',
  },
  {
    path: '/media-planner',
    labelKey: 'nav.mediaPlanner',
    icon: 'CalendarRange',
    section: 'intelligence',
    phase: 'Phase 5',
  },
  {
    path: '/budget-calculator',
    labelKey: 'nav.budgetCalculator',
    icon: 'Calculator',
    section: 'intelligence',
    phase: 'Phase 5',
  },
  {
    path: '/reports',
    labelKey: 'nav.reports',
    icon: 'FileChartColumn',
    section: 'intelligence',
    phase: 'Phase 7',
    permission: 'projects.view_assigned',
  },
  {
    path: '/ai-assistant',
    labelKey: 'nav.aiAssistant',
    icon: 'Sparkles',
    section: 'intelligence',
    phase: 'Phase 8',
  },
  {
    path: '/presentations',
    labelKey: 'nav.presentations',
    icon: 'Presentation',
    section: 'intelligence',
    phase: 'Phase 9',
  },
  {
    path: '/integrations',
    labelKey: 'nav.integrations',
    icon: 'Plug',
    section: 'system',
    phase: 'Phase 2',
    permission: 'workspaces.view',
  },
  {
    path: '/notifications',
    labelKey: 'nav.notifications',
    icon: 'Bell',
    section: 'system',
    phase: 'Phase 6',
  },
  {
    path: '/administration',
    labelKey: 'nav.administration',
    icon: 'Settings2',
    section: 'system',
    phase: 'Phase 1',
    permission: 'workspaces.update',
  },
];

export const NAV_SECTIONS: readonly NavSection[] = ['work', 'intelligence', 'system'];
