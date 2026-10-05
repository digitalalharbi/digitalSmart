/*
 * The page component is not exported here. It imports the charting engine through
 * its widgets, so a barrel that re-exported it would pull a 600 kB chunk into the
 * initial bundle through anything importing a hook from this feature. The router
 * imports the page module directly, lazily.
 */
export { DashboardCanvas } from './DashboardCanvas';
export { WidgetEditor } from './WidgetEditor';
export {
  useDashboardsQuery,
  useDashboardQuery,
  useSaveDashboard,
  useDeleteDashboard,
  useDuplicateDashboard,
} from './useDashboards';
export * from './types';
