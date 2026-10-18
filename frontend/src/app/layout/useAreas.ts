import { useMemo } from 'react';
import { useLocation } from 'react-router';
import {
  areaForPath,
  topAreaKeys,
  visibleAreas,
  type AreaKey,
  type VisibleArea,
} from '@/app/areas';
import type { NavItem } from '@/app/navigation';
import { useSession, type PermissionSlug } from '@/features/auth';
import { useHomeProfiles } from '@/features/home';

/** The areas this person may enter, split into the top bar's set and everything else. */
export function useAreas(): {
  all: VisibleArea[];
  top: VisibleArea[];
  more: VisibleArea[];
  current: AreaKey | null;
} {
  const { can } = useSession();
  const { pathname } = useLocation();
  const { data } = useHomeProfiles();

  return useMemo(() => {
    const all = visibleAreas(
      (item: NavItem) => item.permission === undefined || can(item.permission as PermissionSlug),
    );
    const wanted = topAreaKeys(data?.primary ?? null);
    const top = wanted.flatMap((key) => all.filter((area) => area.key === key));
    /*
     * A person whose role set would leave the bar empty (say, one with no overview) still gets a usable bar:
     * fall back to the first areas they may enter rather than an empty pill.
     */
    const shown = top.length > 0 ? top : all.slice(0, 5);

    return {
      all,
      top: shown,
      more: all.filter((area) => !shown.includes(area)),
      current: areaForPath(pathname),
    };
  }, [can, data?.primary, pathname]);
}
