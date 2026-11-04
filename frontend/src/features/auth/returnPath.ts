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
