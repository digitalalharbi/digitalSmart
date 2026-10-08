import type { ReactElement } from 'react';
import { Navigate } from 'react-router';
import { OverviewPage } from '@/features/overview';
import { PERMISSIONS, useSession } from '@/features/auth';

/**
 * Which shell a signed-in reader belongs in.
 *
 * Its own module rather than living in `routes.tsx`, which exports a route table and not components — a file that
 * exports both breaks fast refresh for everything in it.
 *
 * The rule is `portal.read` **without** `portal.view`, which is exactly what the server applies: the owner role
 * holds every permission by construction, so `portal.read` alone cannot mean "is a client". It is expressed here
 * as well as there because the two answer different questions — the server decides what may be read, this decides
 * which application to render — and a client landing on the agency's overview would be looking at a page of
 * surfaces they cannot open.
 */
function useIsClientReader(): boolean {
  const { can } = useSession();

  return can(PERMISSIONS.portalRead) && !can(PERMISSIONS.portalView);
}

/** The agency's overview, or the portal for somebody who has no business on it. */
export function PortalLanding() {
  return useIsClientReader() ? <Navigate to="/portal" replace /> : <OverviewPage />;
}

/**
 * Keeps agency staff out of the portal shell unless they are previewing deliberately.
 *
 * A previewer reaching `/portal` is legitimate — reading a client's page as the client is the thing to do before
 * switching a module on — so this only turns away a reader who is neither: somebody with no portal to read gets
 * the agency's own application rather than an empty shell.
 */
export function PortalRedirectGuard({ children }: { children: ReactElement }) {
  const { can } = useSession();

  if (!can(PERMISSIONS.portalRead) && !can(PERMISSIONS.portalView)) {
    return <Navigate to="/" replace />;
  }

  return children;
}
