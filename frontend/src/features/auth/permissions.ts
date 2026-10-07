/**
 * Permission slugs, mirroring the backend's Permission enum.
 *
 * These drive what the UI *offers*. They are never the security boundary —
 * every endpoint re-checks on the server.
 */
export const PERMISSIONS = {
  workspacesView: 'workspaces.view',
  workspacesUpdate: 'workspaces.update',
  workspacesManageMembers: 'workspaces.manage_members',
  rolesView: 'roles.view',
  rolesManage: 'roles.manage',
  usersView: 'users.view',
  usersInvite: 'users.invite',
  usersUpdate: 'users.update',
  usersDeactivate: 'users.deactivate',
  clientsView: 'clients.view',
  clientsCreate: 'clients.create',
  clientsUpdate: 'clients.update',
  clientsDelete: 'clients.delete',
  projectsViewAll: 'projects.view_all',
  projectsViewAssigned: 'projects.view_assigned',
  projectsCreate: 'projects.create',
  projectsUpdate: 'projects.update',
  projectsDelete: 'projects.delete',
  projectsManageMembers: 'projects.manage_members',
  analyticsView: 'analytics.view',
  analyticsExport: 'analytics.export',
  savedViewsManage: 'saved_views.manage',
  budgetsView: 'budgets.view',
  budgetsManage: 'budgets.manage',
  /*
   * Separate from managing, as on the server: drafting a plan and committing a client
   * to a figure are different acts, so the approve control is hidden from people who
   * would be refused it anyway.
   */
  budgetsApprove: 'budgets.approve',
  /*
   * Leads and tracking. `leads.manage` is separate from viewing for the same reason
   * approving a budget is: reading a pipeline and judging a prospect worthless are
   * different acts, and the second one leaves a record with somebody's name on it.
   */
  leadsView: 'leads.view',
  leadsManage: 'leads.manage',
  trackingView: 'tracking.view',
  trackingManage: 'tracking.manage',
  /*
   * Approving is separate from managing for the same reason approving a budget is: producing the work
   * and signing it off are different acts, and the separation is what makes "approved" mean anything on
   * an approval trail.
   */
  creativeView: 'creative.view',
  creativeManage: 'creative.manage',
  creativeApprove: 'creative.approve',
  /*
   * One permission for creating, moving and assigning. Deliberately not split the way creative and budget
   * approval are: nothing about moving a card is a judgement somebody else needs protecting from, and a
   * board where only a manager may tick something done is a board kept by hand.
   */
  tasksView: 'tasks.view',
  tasksManage: 'tasks.manage',
  auditView: 'audit.view',
} as const;

export type PermissionSlug = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];
