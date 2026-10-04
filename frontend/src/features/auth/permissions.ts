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
  auditView: 'audit.view',
} as const;

export type PermissionSlug = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];
