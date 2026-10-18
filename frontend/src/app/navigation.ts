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
    path: '/portfolio',
    labelKey: 'nav.portfolio',
    icon: 'BriefcaseBusiness',
    section: 'work',
    phase: 'Phase 4',
    permission: 'analytics.view',
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
    phase: 'Phase 19',
    // The permission the endpoints check. A client viewer is refused outright.
    permission: 'creative.view',
  },
  {
    path: '/automation',
    labelKey: 'nav.automation',
    icon: 'Zap',
    section: 'intelligence',
    phase: 'Phase 20',
    // Reading rules needs what reading performance needs; writing them is gated again, in the page and on the server.
    permission: 'analytics.view',
  },
  {
    path: '/today',
    labelKey: 'nav.today',
    icon: 'CalendarCheck',
    section: 'work',
    phase: 'Phase 20',
  },
  {
    path: '/tasks',
    labelKey: 'nav.tasks',
    icon: 'ListChecks',
    section: 'work',
    phase: 'Phase 20',
    // The permission the endpoints check. A client viewer is refused outright: an agency's board carries
    // its own reasons for things being blocked, and that is an internal conversation.
    permission: 'tasks.view',
  },
  {
    path: '/client-requests',
    labelKey: 'nav.requests',
    icon: 'Inbox',
    section: 'work',
    phase: 'Phase 20',
    /*
     * The one surface in this product a client viewer can reach and write to. `submit` rather than `view`: an
     * intake queue only agency staff can write to is just another task list.
     */
    permission: 'client_requests.submit',
  },
  {
    path: '/approvals',
    labelKey: 'nav.approvals',
    icon: 'BadgeCheck',
    section: 'work',
    phase: 'Phase 20',
    // The permission the endpoints check. A client viewer is refused outright.
    permission: 'approvals.view',
  },
  {
    path: '/tracking',
    labelKey: 'nav.tracking',
    icon: 'Radar',
    section: 'intelligence',
    phase: 'Phase 10',
    /*
     * Gated on the permission the endpoints actually check. Offering a surface to a role that
     * would receive a 403 is worse than not offering it.
     */
    permission: 'tracking.view',
  },
  {
    path: '/leads',
    labelKey: 'nav.leads',
    icon: 'UserRoundSearch',
    section: 'intelligence',
    phase: 'Phase 17',
    // The permission the endpoints check. Offering a surface that would answer 403 is worse
    // than not offering it.
    permission: 'leads.view',
  },
  {
    path: '/commerce',
    labelKey: 'nav.commerce',
    icon: 'ShoppingBag',
    section: 'intelligence',
    phase: 'Phase 14',
    // The endpoint checks the same permission the rest of the reporting surface does.
    permission: 'analytics.view',
  },
  {
    path: '/commerce/stores',
    labelKey: 'nav.stores',
    icon: 'Store',
    section: 'intelligence',
    phase: 'Phase 14',
    permission: 'analytics.view',
  },
  {
    path: '/attribution',
    labelKey: 'nav.attribution',
    icon: 'GitBranch',
    section: 'intelligence',
    phase: 'Phase 18',
    // The endpoint checks the same permission the rest of the reporting surface does.
    permission: 'analytics.view',
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
    permission: 'budgets.view',
  },
  {
    path: '/budget-control',
    labelKey: 'nav.budgetControl',
    icon: 'Landmark',
    section: 'intelligence',
    phase: 'Phase 5',
    permission: 'budgets.view',
  },
  {
    path: '/budget-calculator',
    labelKey: 'nav.budgetCalculator',
    icon: 'Calculator',
    section: 'intelligence',
    phase: 'Phase 5',
    permission: 'budgets.view',
  },
  {
    path: '/reports',
    labelKey: 'nav.reports',
    icon: 'FileChartColumn',
    section: 'intelligence',
    phase: 'Phase 21',
    // The permission the endpoints check. A client viewer and a creative are refused outright.
    permission: 'reports.view',
  },
  {
    path: '/client-portal',
    labelKey: 'nav.clientPortal',
    icon: 'DoorOpen',
    section: 'system',
    phase: 'Phase 22',
    /*
     * `portal.view` rather than `portal.manage`: knowing whether a client can already see this week's figures is
     * a question anybody handling the account asks, often with the client on the phone. Changing it is a separate
     * permission the page checks for itself.
     */
    permission: 'portal.view',
  },
  {
    path: '/ai-assistant',
    labelKey: 'nav.aiAssistant',
    icon: 'Sparkles',
    section: 'intelligence',
    phase: 'Phase 23',
    /*
     * The same permission the analytics surface uses. An assistant that answered questions a reader may not ask
     * directly would be a route around every other gate in this product.
     */
    permission: 'analytics.view',
  },
  {
    path: '/presentations',
    labelKey: 'nav.presentations',
    icon: 'Presentation',
    section: 'intelligence',
    phase: 'Phase 24',
    /*
     * A deck is a report laid out for a room, so it is gated on the permission a report is. A reader who may not
     * read a client's report has no business generating one with the agency's name on it.
     */
    permission: 'reports.view',
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
    phase: 'Phase 20',
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
