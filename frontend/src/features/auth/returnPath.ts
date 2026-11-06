/**
 * Where to send somebody after they sign in: the page they were headed for, if and only if it is a page of this app.
 *
 * The remembered path is router state set by `RequireSession`, so a link cannot supply it — but it is still checked, because
 * "this can only come from our own code" is the assumption an open redirect is built on. Only a single-slash path is
 * accepted: `//host` and `/\\host` are protocol-relative to a browser, a scheme is an absolute URL, and `/login` would loop.
 * Anything else, or nothing, is the home screen.
 */
export function safeReturnPath(candidate: unknown): string {
  if (typeof candidate !== 'string' || candidate === '' || candidate.length > 512) {
    return '/';
  }

  if (!candidate.startsWith('/') || candidate.startsWith('//') || candidate.includes('\\')) {
    return '/';
  }

  for (const character of candidate) {
    const code = character.charCodeAt(0);

    if (code < 0x20 || code === 0x7f) {
      return '/';
    }
  }

  const path = candidate.split(/[?#]/, 1)[0] ?? '';

  if (path === '/login' || path.startsWith('/login/')) {
    return '/';
  }

  return candidate;
}

/** The part of a session the destination depends on, so a test can state it without building a whole user. */
export type DestinationReader = {
  permissions: readonly string[];
  active_workspace: unknown;
  is_platform_operator?: boolean | undefined;
};

function isUnder(path: string, root: string): boolean {
  return path === root || path.startsWith(`${root}/`);
}

/**
 * Where a person lands after signing in: the page they were headed for when it belongs to the application they use,
 * and otherwise the front door of that application.
 *
 * Three applications share one sign-in, and a remembered path can point into the wrong one — a link a colleague
 * forwarded, a bookmark from a previous role, or the last page somebody else opened in this browser:
 *
 * - **A client** (`portal.read` without `portal.view`, the server's own rule) reads their portal and nothing else. Any
 *   other path would draw the agency's application around surfaces they cannot open, so they go to `/portal`.
 * - **Agency staff** with no portal to read or preview are sent home from `/portal` rather than into an empty shell.
 * - **A platform operator with no workspace** has no agency application at all; the console is the only page they have.
 *   An operator who is also a member keeps their remembered path, and `/platform` is offered to nobody else.
 *
 * This decides which application to render, never what may be read: every page still asks the server.
 */
export function signInDestination(candidate: unknown, reader: DestinationReader): string {
  const path = safeReturnPath(candidate);
  const bare = path.split(/[?#]/, 1)[0] ?? '/';
  const can = (permission: string): boolean => reader.permissions.includes(permission);
  const operator = reader.is_platform_operator === true;

  if (reader.active_workspace === null || reader.active_workspace === undefined) {
    return operator ? '/platform' : '/';
  }

  if (isUnder(bare, '/platform') && !operator) {
    return '/';
  }

  if (can('portal.read') && !can('portal.view')) {
    return isUnder(bare, '/portal') ? path : '/portal';
  }

  if (isUnder(bare, '/portal') && !can('portal.view') && !can('portal.read')) {
    return '/';
  }

  return path;
}
