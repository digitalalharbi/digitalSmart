import { NAV_ITEMS, type NavItem } from '@/app/navigation';
import type { HomeProfileKey } from '@/features/home';

/**
 * The product's eleven areas.
 *
 * The product used to offer twenty-odd destinations side by side, and a person had to know which module answered
 * which question. Each area now answers exactly one question, and the destinations that used to be top-level
 * pages are the tabs inside the area that owns them. Routes are unchanged — only where a person *finds* a page.
 *
 * Permissions are not restated here: an area is visible when any of its destinations is, so `NAV_ITEMS` stays the
 * single place that says who may see what.
 */
export type AreaKey =
  | 'overview'
  | 'clients'
  | 'campaigns'
  | 'automation'
  | 'performance'
  | 'finance'
  | 'creative'
  | 'work'
  | 'reports'
  | 'integrations'
  | 'settings';

export type Area = {
  key: AreaKey;
  labelKey: string;
  icon: string;
  /** Destinations in tab order; the first the person may see is where the area opens. */
  paths: readonly string[];
};

export const AREAS: readonly Area[] = [
  {
    key: 'overview',
    labelKey: 'areas.overview',
    icon: 'LayoutDashboard',
    paths: ['/', '/dashboards', '/ai-assistant'],
  },
  {
    key: 'clients',
    labelKey: 'areas.clients',
    icon: 'Building2',
    paths: ['/clients', '/projects', '/portfolio'],
  },
  {
    key: 'campaigns',
    labelKey: 'areas.campaigns',
    icon: 'Megaphone',
    paths: ['/campaigns', '/media-planner'],
  },
  /* Rules are control, agents are reasoning, approvals are governance — the automation operating centre. */
  {
    key: 'automation',
    labelKey: 'areas.automation',
    icon: 'Bot',
    paths: [
      '/automation',
      '/automation/agents',
      '/automation/routines',
      '/automation/history',
      '/automation/changes',
    ],
  },
  {
    key: 'performance',
    labelKey: 'areas.performance',
    icon: 'ChartNoAxesCombined',
    paths: ['/analytics', '/leads', '/commerce', '/commerce/stores', '/attribution', '/tracking'],
  },
  {
    key: 'creative',
    labelKey: 'areas.creative',
    icon: 'Palette',
    paths: ['/creative-hub', '/creatives'],
  },
  {
    key: 'work',
    labelKey: 'areas.work',
    icon: 'ListChecks',
    paths: ['/today', '/tasks', '/approvals', '/client-requests'],
  },
  {
    key: 'finance',
    labelKey: 'areas.finance',
    icon: 'Landmark',
    paths: ['/budget-control', '/budget-calculator'],
  },
  {
    key: 'reports',
    labelKey: 'areas.reports',
    icon: 'FileChartColumn',
    paths: ['/reports', '/presentations'],
  },
  { key: 'integrations', labelKey: 'areas.integrations', icon: 'Plug', paths: ['/integrations'] },
  {
    key: 'settings',
    labelKey: 'areas.settings',
    icon: 'Settings2',
    paths: ['/administration', '/ai-center', '/client-portal', '/notifications'],
  },
];

/**
 * What each kind of person sees in the top navigation. The rest stay one click away on the rail, so nobody is
 * locked out of an area they have the permission for — the top bar is just the part that is relevant all day.
 */
export const ROLE_AREAS: Record<HomeProfileKey, readonly AreaKey[]> = {
  executive: ['overview', 'clients', 'performance', 'automation', 'reports', 'finance'],
  media_buyer: ['overview', 'campaigns', 'automation', 'performance', 'work'],
  project_manager: ['overview', 'clients', 'automation', 'work', 'reports'],
  finance: ['overview', 'finance', 'clients', 'reports'],
  creative: ['overview', 'creative', 'work'],
};

const DEFAULT_TOP: readonly AreaKey[] = [
  'overview',
  'clients',
  'campaigns',
  'automation',
  'performance',
  'reports',
];

export type VisibleArea = Area & { items: NavItem[]; home: string };

/** The areas a person may enter, each with the destinations they may see inside it. */
export function visibleAreas(canSee: (item: NavItem) => boolean): VisibleArea[] {
  return AREAS.flatMap((area) => {
    const items = area.paths
      .map((path) => NAV_ITEMS.find((item) => item.path === path))
      .filter((item): item is NavItem => item !== undefined && canSee(item));

    const home = items[0];

    return home === undefined ? [] : [{ ...area, items, home: home.path }];
  });
}

/** Which areas go on the top bar, in order, for this person. */
export function topAreaKeys(profile: HomeProfileKey | null): readonly AreaKey[] {
  return profile === null ? DEFAULT_TOP : ROLE_AREAS[profile];
}

/** The area a path belongs to, so the right one reads as current on every page inside it. */
export function areaForPath(pathname: string): AreaKey | null {
  for (const area of AREAS) {
    for (const path of area.paths) {
      const inside =
        path === '/' ? pathname === '/' : pathname === path || pathname.startsWith(`${path}/`);

      if (inside) {
        return area.key;
      }
    }
  }

  return null;
}
