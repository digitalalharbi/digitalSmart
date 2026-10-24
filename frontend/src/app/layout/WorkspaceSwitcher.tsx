import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { Building2, Check, ChevronsUpDown } from 'lucide-react';
import { useSession, useSwitchWorkspaceMutation } from '@/features/auth';
import { roleLabel } from '@/shared/lib/role-label';
import { Button } from '@/shared/ui/button';

/**
 * Which workspace the reader is working in, and the way to another one.
 *
 * Shown only to somebody who belongs to more than one. Switching is a server call that re-checks the membership; when it
 * answers, every cached read of the previous workspace is thrown away (not refreshed in place, which would show the old
 * tenant's figures until the new ones arrive), the client and project context is cleared, and the reader lands on the new
 * workspace's home — a page addressed by an id from the old workspace would only be "not found" there.
 */
export function WorkspaceSwitcher() {
  const { t } = useTranslation();
  const { user } = useSession();
  const navigate = useNavigate();
  const switchWorkspace = useSwitchWorkspaceMutation();

  if (!user || user.workspaces.length < 2) {
    return null;
  }

  const active = user.active_workspace;

  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger asChild>
        <Button
          variant="ghost"
          className="h-12 min-w-0 gap-2 rounded-full px-3"
          aria-label={t('workspace.switcher', { name: active?.name ?? '' })}
        >
          <Building2 aria-hidden className="text-content-tertiary size-4 shrink-0" />
          {/*
            The name only where the header has room for it. Beside the navigation pill on a laptop it squeezed the
            pill's links below the WCAG 2.2 target size; the button keeps its accessible name either way.
          */}
          <span className="text-content hidden max-w-44 truncate text-sm font-medium 2xl:inline">
            {active?.name}
          </span>
          <ChevronsUpDown
            aria-hidden
            className="text-content-tertiary hidden size-4 shrink-0 2xl:block"
          />
        </Button>
      </DropdownMenu.Trigger>

      <DropdownMenu.Portal>
        <DropdownMenu.Content
          align="end"
          sideOffset={6}
          className="bg-surface border-line z-50 max-w-[calc(100vw-2rem)] min-w-64 rounded-lg border p-1 shadow-md"
        >
          <DropdownMenu.Label className="text-content-tertiary px-2.5 py-1 text-xs">
            {t('workspace.yours')}
          </DropdownMenu.Label>
          {switchWorkspace.isError ? (
            <p role="alert" className="text-danger px-2.5 pb-1 text-xs">
              {t('workspace.switchFailed')}
            </p>
          ) : null}
          {user.workspaces.map((workspace) => {
            const isActive = workspace.id === active?.id;

            return (
              <DropdownMenu.Item
                key={workspace.id}
                disabled={switchWorkspace.isPending}
                onSelect={() => {
                  if (!isActive) {
                    switchWorkspace.mutate(workspace.id, { onSuccess: () => void navigate('/') });
                  }
                }}
                className="text-content hover:bg-surface-hover focus:bg-surface-hover flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-1.5 text-sm outline-none pointer-coarse:min-h-11"
              >
                <Check
                  className={
                    isActive ? 'text-content-brand size-4 shrink-0' : 'invisible size-4 shrink-0'
                  }
                  aria-hidden
                />
                <span className="flex min-w-0 flex-col">
                  <span className="truncate">{workspace.name}</span>
                  <span className="text-content-tertiary text-xs">
                    {t(`workspace.type.${workspace.type}`)} ·{' '}
                    {roleLabel(t, workspace.role, workspace.role_name)}
                  </span>
                </span>
                {isActive ? <span className="sr-only">{t('workspace.current')}</span> : null}
              </DropdownMenu.Item>
            );
          })}
        </DropdownMenu.Content>
      </DropdownMenu.Portal>
    </DropdownMenu.Root>
  );
}
