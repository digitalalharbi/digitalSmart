import type { RouteObject } from 'react-router';
import { AppShell } from '@/app/layout/AppShell';
import { NAV_ITEMS } from '@/app/navigation';
import { NotFoundPage } from '@/app/router/NotFoundPage';
import { RequireSession } from '@/app/router/RequireSession';
import { LoginPage } from '@/features/auth';
import { AdministrationPage } from '@/features/administration';
import { ClientsPage } from '@/features/clients';
import { OverviewPage } from '@/features/overview';
import { ProjectsPage } from '@/features/projects';
import { PlaceholderPage } from '@/features/placeholder';

/** Surfaces whose real page has landed. The rest fall back to an honest placeholder. */
const IMPLEMENTED: Record<string, React.ReactElement> = {
  '/clients': <ClientsPage />,
  '/projects': <ProjectsPage />,
  '/administration': <AdministrationPage />,
};

const featureRoutes: RouteObject[] = NAV_ITEMS.filter((item) => item.path !== '/').map((item) => ({
  path: item.path.replace(/^\//, ''),
  element: IMPLEMENTED[item.path] ?? (
    <PlaceholderPage titleKey={item.labelKey} phase={item.phase} />
  ),
}));

export const routes: RouteObject[] = [
  { path: '/login', element: <LoginPage /> },
  {
    path: '/',
    element: (
      <RequireSession>
        <AppShell />
      </RequireSession>
    ),
    children: [
      { index: true, element: <OverviewPage /> },
      ...featureRoutes,
      { path: '*', element: <NotFoundPage /> },
    ],
  },
];
