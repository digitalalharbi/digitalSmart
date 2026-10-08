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
  /*
   * Asking and deciding are separate, which is the whole point of the module: somebody who could approve their
   * own change to a client's spend has an approvals screen rather than an approvals process. Some kinds need a
   * second permission on top of deciding — the server publishes which, per kind.
   */
  approvalsView: 'approvals.view',
  approvalsRequest: 'approvals.request',
  approvalsDecide: 'approvals.decide',
  /*
   * Three, and the split is what makes a client portal possible: `view` opens the agency's intake queue, which
   * carries internal notes, while `submit` only lets somebody raise a request and read the client-facing side of
   * their own. A client viewer holds `submit` and not `view`, and the absence of `view` is what tells the server
   * to exclude internal messages in SQL.
   */
  clientRequestsView: 'client_requests.view',
  clientRequestsSubmit: 'client_requests.submit',
  clientRequestsTriage: 'client_requests.triage',
  /*
   * Sharing is its own permission, and the one that matters: `manage` builds a document, `share` hands a client's
   * performance data to whoever holds a link. A media buyer has the first and not the second — somebody reporting on
   * their own performance to a client, with nobody reading it first, is the one review this keeps.
   */
  reportsView: 'reports.view',
  reportsManage: 'reports.manage',
  reportsShare: 'reports.share',
  /*
   * Three, and the split is what the client portal rests on. `view` reads which surfaces a client's portal
   * exposes — a question anybody handling the account asks, often with the client on the phone. `manage` changes
   * it, which is a disclosure decision. `read` is held by the client's own people and by nobody in the agency: it
   * is the only thing in this product that says "this person belongs to the client", because project assignment
   * says what somebody works on rather than who they work for.
   */
  portalView: 'portal.view',
  portalManage: 'portal.manage',
  portalRead: 'portal.read',
  auditView: 'audit.view',
} as const;

export type PermissionSlug = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];
