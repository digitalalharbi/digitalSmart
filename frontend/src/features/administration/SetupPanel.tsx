import { ArrowUpRight } from 'lucide-react';
import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';

type Area = { key: string; to: string; allowed: boolean };

/**
 * Where the rest of the setup lives.
 *
 * Clients, integrations, tracking, stores and notifications each have a working page of their own; copying them
 * into a second place would give the agency two implementations to keep honest. This is the index: one list, in
 * the order an onboarding goes, with each entry shown only to somebody who can open it.
 */
export function SetupPanel() {
  const { t } = useTranslation();
  const { can } = useSession();

  const areas: Area[] = [
    { key: 'clients', to: '/clients', allowed: can(PERMISSIONS.clientsView) },
    { key: 'integrations', to: '/integrations', allowed: can(PERMISSIONS.workspacesView) },
    { key: 'tracking', to: '/tracking', allowed: can(PERMISSIONS.trackingView) },
    { key: 'stores', to: '/commerce/stores', allowed: can(PERMISSIONS.analyticsView) },
    { key: 'benchmarks', to: '/media-planner', allowed: can(PERMISSIONS.budgetsView) },
    { key: 'notifications', to: '/notifications', allowed: true },
  ];

  return (
    <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-label={t('administration.setup')}>
      {areas
        .filter((area) => area.allowed)
        .map((area) => (
          <li key={area.key}>
            <Link
              to={area.to}
              className="bg-surface border-line-subtle hover:border-line-strong focus-visible:ring-accent/40 flex h-full flex-col gap-1 rounded-2xl border p-4 shadow-xs focus-visible:ring-2 focus-visible:outline-none"
            >
              <span className="text-content flex items-center justify-between text-sm font-semibold">
                {t(`administration.area.${area.key}.title`)}
                <ArrowUpRight
                  aria-hidden
                  className="text-content-tertiary size-4 rtl:-scale-x-100"
                />
              </span>
              <span className="text-content-secondary text-sm">
                {t(`administration.area.${area.key}.body`)}
              </span>
            </Link>
          </li>
        ))}
    </ul>
  );
}
