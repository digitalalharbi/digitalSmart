import {
  BadgeCheck,
  Bell,
  Building2,
  Calculator,
  CalendarRange,
  ChartNoAxesCombined,
  FileChartColumn,
  FolderKanban,
  LayoutDashboard,
  ListChecks,
  Megaphone,
  Palette,
  Plug,
  Presentation,
  Settings2,
  Sparkles,
  type LucideIcon,
} from 'lucide-react';

const ICONS: Record<string, LucideIcon> = {
  BadgeCheck,
  Bell,
  Building2,
  Calculator,
  CalendarRange,
  ChartNoAxesCombined,
  FileChartColumn,
  FolderKanban,
  LayoutDashboard,
  ListChecks,
  Megaphone,
  Palette,
  Plug,
  Presentation,
  Settings2,
  Sparkles,
};

export function NavIcon({ name, className }: { name: string; className?: string }) {
  const Icon = ICONS[name] ?? LayoutDashboard;
  return <Icon className={className} aria-hidden="true" />;
}
