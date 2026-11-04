import { describe, expect, it } from 'vitest';
import { safeReturnPath } from './returnPath';

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
