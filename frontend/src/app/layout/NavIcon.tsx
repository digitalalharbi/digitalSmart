import { FALLBACK_NAV_ICON, NAV_ICONS } from './navIcons';

export function NavIcon({ name, className }: { name: string; className?: string }) {
  const Icon = NAV_ICONS[name] ?? FALLBACK_NAV_ICON;

  return <Icon className={className} aria-hidden="true" />;
}
