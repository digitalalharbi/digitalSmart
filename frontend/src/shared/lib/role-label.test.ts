import type { TFunction } from 'i18next';
import { describe, expect, it } from 'vitest';
import ar from '@/shared/i18n/locales/ar.json';
import en from '@/shared/i18n/locales/en.json';
import { roleLabel } from './role-label';

/** A `t` that answers from a catalogue and otherwise honours `defaultValue`, as i18next does. */
function translator(catalogue: Record<string, string>): TFunction {
  return ((key: string, options?: { defaultValue?: string }) =>
    catalogue[key] ?? options?.defaultValue ?? key) as unknown as TFunction;
}

const SYSTEM_ROLES = [
  'owner',
  'admin',
  'project-manager',
  'media-buyer',
  'creative',
  'client-viewer',
] as const;

describe('roleLabel', () => {
  it('names a system role from the locale, whatever the server called it', () => {
    const t = translator({ 'administration.roles.media-buyer': 'مسؤول الحملات' });

    expect(roleLabel(t, 'media-buyer', 'Media buyer')).toBe('مسؤول الحملات');
  });

  it('keeps the name a workspace gave a role of its own', () => {
    expect(roleLabel(translator({}), 'regional-lead', 'Regional lead')).toBe('Regional lead');
  });
});

describe('Arabic role vocabulary', () => {
  const roles = ar.administration.roles as Record<string, string>;

  it('names every system role in both languages, by the slug the server publishes', () => {
    for (const slug of SYSTEM_ROLES) {
      expect(roles[slug], slug).toBeTruthy();
      expect((en.administration.roles as Record<string, string>)[slug], slug).toBeTruthy();
    }
  });

  it('uses the short names an agency uses, not a word-for-word translation', () => {
    expect(roles['owner']).toBe('مالك النظام');
    expect(roles['admin']).toBe('مدير النظام');
    expect(roles['project-manager']).toBe('مدير المشاريع');
    expect(roles['media-buyer']).toBe('مسؤول الحملات');
    expect(roles['client-viewer']).toBe('العميل');
    expect(Object.values(roles).join(' ')).not.toContain('وسائط');
  });
});
