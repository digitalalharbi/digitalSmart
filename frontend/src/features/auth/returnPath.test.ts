import { describe, expect, it } from 'vitest';
import { safeReturnPath, signInDestination } from './returnPath';

describe('safeReturnPath', () => {
  it('returns a page of this app', () => {
    expect(safeReturnPath('/reports')).toBe('/reports');
    expect(safeReturnPath('/reports/abc/manage?tab=links#top')).toBe(
      '/reports/abc/manage?tab=links#top',
    );
  });

  it.each([
    'https://evil.example/',
    '//evil.example/path',
    '/\\evil.example',
    'javascript:alert(1)',
    'reports',
    '',
    '/ok\nSet-Cookie: x=1',
    '/login',
    '/login/anything',
    '/login?x=1',
  ])('sends %j home instead', (candidate) => {
    expect(safeReturnPath(candidate)).toBe('/');
  });

  it('sends anything that is not a string home', () => {
    expect(safeReturnPath(undefined)).toBe('/');
    expect(safeReturnPath(null)).toBe('/');
    expect(safeReturnPath({ from: '/reports' })).toBe('/');
    expect(safeReturnPath('/' + 'a'.repeat(600))).toBe('/');
  });
});

describe('signInDestination', () => {
  const workspace = { id: 'w1' };
  const staff = { permissions: ['clients.view', 'portal.view'], active_workspace: workspace };
  const client = {
    permissions: ['portal.read', 'client_requests.submit'],
    active_workspace: workspace,
  };

  it('returns agency staff to the page they were headed for', () => {
    expect(signInDestination('/reports/abc', staff)).toBe('/reports/abc');
    expect(signInDestination('/portal/performance', staff)).toBe('/portal/performance');
  });

  it('sends a client to their portal, whatever page was remembered', () => {
    expect(signInDestination('/clients', client)).toBe('/portal');
    expect(signInDestination(undefined, client)).toBe('/portal');
    expect(signInDestination('/portal/reports?x=1', client)).toBe('/portal/reports?x=1');
  });

  it('keeps staff with no portal out of the portal shell', () => {
    expect(
      signInDestination('/portal', { permissions: ['clients.view'], active_workspace: workspace }),
    ).toBe('/');
  });

  it('opens the console for an operator with no workspace, and offers it to nobody else', () => {
    expect(
      signInDestination('/clients', {
        permissions: [],
        active_workspace: null,
        is_platform_operator: true,
      }),
    ).toBe('/platform');
    expect(signInDestination('/platform', staff)).toBe('/');
    expect(signInDestination('/platform', { ...staff, is_platform_operator: true })).toBe(
      '/platform',
    );
    expect(signInDestination('/clients', { permissions: [], active_workspace: null })).toBe('/');
  });

  it('still refuses anything that is not a page of this app', () => {
    expect(signInDestination('//evil.example', staff)).toBe('/');
    expect(signInDestination('https://evil.example/', client)).toBe('/portal');
  });
});
