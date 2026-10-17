import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Skeleton } from '@/shared/ui/skeleton';

type Roles = {
  roles: Array<{
    slug: string;
    name: string;
    description: string | null;
    is_system: boolean;
    members: number;
    permissions: string[];
  }>;
  permissions: Array<{ slug: string; group: string; description: string }>;
};

/**
 * Who may do what — as the product enforces it, not as a document describes it.
 *
 * Read-only: roles are templates in code and every permission is an enforcement point in code, so editing them
 * from a form would be a deployment dressed as a setting. What an administrator needs is the answer to "what can a
 * media buyer do here", and this is it, down to the permission and its plain-language meaning.
 */
export function RolesPanel() {
  const { t } = useTranslation();
  const { data, isPending, isError } = useQuery({
    queryKey: ['administration', 'roles'] as const,
    queryFn: async (): Promise<Roles> => {
      const { data: body } = await api.get<{ data: Roles }>('/roles');

      return body.data;
    },
    staleTime: 60_000,
  });

  if (isError) {
    return <Alert tone="danger">{t('administration.rolesFailed')}</Alert>;
  }

  if (isPending || data === undefined) {
    return <Skeleton className="h-48 w-full" />;
  }

  const meaning = new Map(data.permissions.map((permission) => [permission.slug, permission]));

  return (
    <ul className="grid gap-4 lg:grid-cols-2" aria-label={t('administration.rolesTitle')}>
      {data.roles.map((role) => {
        const groups = new Map<string, string[]>();

        for (const slug of role.permissions) {
          const group = meaning.get(slug)?.group ?? 'other';
          groups.set(group, [...(groups.get(group) ?? []), slug]);
        }

        return (
          <li
            key={role.slug}
            className="bg-surface border-line-subtle flex flex-col gap-3 rounded-2xl border p-4 shadow-xs"
          >
            <div className="flex items-start justify-between gap-3">
              <div>
                <h3 className="text-content text-base font-semibold">{role.name}</h3>
                {role.description === null ? null : (
                  <p className="text-content-secondary mt-1 text-sm">{role.description}</p>
                )}
              </div>
              <Badge tone="neutral">
                {t('administration.roleMembers', { count: role.members })}
              </Badge>
            </div>
            <details>
              <summary className="text-content-brand cursor-pointer text-sm font-medium">
                {t('administration.rolePermissions', { count: role.permissions.length })}
              </summary>
              <div className="mt-3 flex flex-col gap-3">
                {[...groups.entries()].map(([group, slugs]) => (
                  <div key={group}>
                    <p className="ds-overline text-content-tertiary">{group}</p>
                    <ul className="mt-1 flex flex-col gap-1">
                      {slugs.map((slug) => (
                        <li key={slug} className="text-content-secondary text-sm">
                          {meaning.get(slug)?.description ?? slug}
                        </li>
                      ))}
                    </ul>
                  </div>
                ))}
              </div>
            </details>
          </li>
        );
      })}
    </ul>
  );
}
