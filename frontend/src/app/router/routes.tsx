import type { RouteObject } from 'react-router';
import { AppShell } from '@/app/layout/AppShell';
import { NAV_ITEMS } from '@/app/navigation';
import { OverviewPage } from '@/features/overview';
import { PlaceholderPage } from '@/features/placeholder';
import { NotFoundPage } from '@/app/router/NotFoundPage';

/**
 * Routes are derived from NAV_ITEMS so navigation and routing cannot drift apart.
 * Each surface is replaced with its real page as its phase lands.
 */
const placeholderRoutes: RouteObject[] = NAV_ITEMS.filter((item) => item.path !== '/').map(
  (item) => ({
    path: item.path.replace(/^\//, ''),
    element: <PlaceholderPage titleKey={item.labelKey} phase={item.phase} />,
  }),
);

export const routes: RouteObject[] = [
  {
    path: '/',
    element: <AppShell />,
    children: [
      { index: true, element: <OverviewPage /> },
      ...placeholderRoutes,
      { path: '*', element: <NotFoundPage /> },
    ],
  },
];
