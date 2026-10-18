import {
  BadgeCheck,
  Bell,
  Bot,
  BriefcaseBusiness,
  Building2,
  Calculator,
  CalendarCheck,
  CalendarRange,
  ChartNoAxesCombined,
  DoorOpen,
  FileChartColumn,
  FolderKanban,
  GitBranch,
  Inbox,
  Landmark,
  LayoutDashboard,
  LayoutGrid,
  ListChecks,
  Megaphone,
  Palette,
  Plug,
  Presentation,
  Radar,
  Settings2,
  ShoppingBag,
  Store,
  Sparkles,
  UserRoundSearch,
  Zap,
  type LucideIcon,
} from 'lucide-react';

/**
 * Every icon the navigation can name.
 *
 * A name that is not here falls back to the overview icon without complaint, which is how
 * Dashboards, Tracking and Store revenue all came to wear one icon — three surfaces
 * indistinguishable in a sidebar nobody had looked at closely. `navigation.test.ts` now holds
 * this list and `NAV_ITEMS` to each other.
 *
 * Its own module rather than living beside the component, so the registry can be imported by
 * a test without dragging a component import along with it.
 */
export const NAV_ICONS: Record<string, LucideIcon> = {
  BadgeCheck,
  Bell,
  Bot,
  BriefcaseBusiness,
  Building2,
  Calculator,
  CalendarCheck,
  CalendarRange,
  ChartNoAxesCombined,
  DoorOpen,
  FileChartColumn,
  FolderKanban,
  GitBranch,
  Inbox,
  Landmark,
  LayoutDashboard,
  LayoutGrid,
  ListChecks,
  Megaphone,
  Palette,
  Plug,
  Presentation,
  Radar,
  Settings2,
  ShoppingBag,
  Store,
  Sparkles,
  UserRoundSearch,
  Zap,
};

/** What an unknown name renders as. */
export const FALLBACK_NAV_ICON = LayoutDashboard;
