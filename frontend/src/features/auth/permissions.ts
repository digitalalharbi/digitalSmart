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
  auditView: 'audit.view',
} as const;

export type PermissionSlug = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];
