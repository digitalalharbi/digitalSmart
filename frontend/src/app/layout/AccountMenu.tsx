import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { Check, ChevronsUpDown, KeyRound, LogOut } from 'lucide-react';
import { useState } from 'react';
import {
  PasswordDialog,
  useLogoutMutation,
  useSession,
  useSwitchWorkspaceMutation,
} from '@/features/auth';
import { Button } from '@/shared/ui/button';

/**
 * Identity, workspace switching and sign-out.
 *
 * Switching workspace is a server call: membership is re-verified and every
 * cached query for the previous workspace is discarded.
 */
export function AccountMenu() {
  const { t } = useTranslation();
  const { user } = useSession();
  const navigate = useNavigate();
  const logout = useLogoutMutation();
  const switchWorkspace = useSwitchWorkspaceMutation();
  const [isChangingPassword, setIsChangingPassword] = useState(false);

  if (!user) {
    return null;
  }

  const initials = user.name
    .split(' ')
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('');

  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger asChild>
        <Button variant="ghost" size="sm" className="gap-2 px-2" aria-label={t('account.menu')}>
          <span
            aria-hidden="true"
            className="bg-accent-subtle text-content-brand grid size-7 place-items-center rounded-full text-xs font-semibold"
          >
            {initials}
          </span>
          <span className="hidden max-w-32 truncate sm:inline">{user.active_workspace?.name}</span>
          <ChevronsUpDown className="text-content-tertiary" />
        </Button>
      </DropdownMenu.Trigger>

      <DropdownMenu.Portal>
        <DropdownMenu.Content
          align="end"
          sideOffset={6}
          className="bg-surface border-line z-50 min-w-60 rounded-lg border p-1 shadow-md"
        >
          <div className="px-2.5 py-2">
            <p className="text-content truncate text-sm font-medium">{user.name}</p>
            <p className="text-content-tertiary truncate text-xs">{user.email}</p>
          </div>

          <DropdownMenu.Separator className="bg-line-subtle my-1 h-px" />

          <DropdownMenu.Label className="text-content-tertiary px-2.5 py-1 text-xs">
            {t('account.workspaces')}
          </DropdownMenu.Label>

          {user.workspaces.map((workspace) => {
            const isActive = workspace.id === user.active_workspace?.id;

            return (
              <DropdownMenu.Item
                key={workspace.id}
                disabled={switchWorkspace.isPending}
                onSelect={() => {
                  if (!isActive) {
                    switchWorkspace.mutate(workspace.id);
                  }
                }}
                className="text-content hover:bg-surface-hover focus:bg-surface-hover flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-1.5 text-sm outline-none"
              >
                <Check className={isActive ? 'text-accent' : 'invisible'} aria-hidden={!isActive} />
                <span className="flex min-w-0 flex-col">
                  <span className="truncate">{workspace.name}</span>
                  <span className="text-content-tertiary text-xs">{workspace.role_name}</span>
                </span>
              </DropdownMenu.Item>
            );
          })}

          <DropdownMenu.Separator className="bg-line-subtle my-1 h-px" />

          {/*
            Here rather than on a settings page, because a password belongs to the person and not to a workspace:
            a media buyer must be able to change theirs without holding `workspaces.update`, and a page behind
            that permission would have put it out of reach of most of the people who need it.
          */}
          <DropdownMenu.Item
            onSelect={() => setIsChangingPassword(true)}
            className="text-content hover:bg-surface-hover focus:bg-surface-hover flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-1.5 text-sm outline-none"
          >
            <KeyRound className="size-4" aria-hidden="true" />
            {t('account.password.title')}
          </DropdownMenu.Item>

          <DropdownMenu.Item
            disabled={logout.isPending}
            onSelect={() => {
              logout.mutate(undefined, {
                onSettled: () => void navigate('/login', { replace: true }),
              });
            }}
            className="text-content hover:bg-surface-hover focus:bg-surface-hover flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-1.5 text-sm outline-none"
          >
            <LogOut className="size-4" aria-hidden="true" />
            {logout.isPending ? t('account.signingOut') : t('account.signOut')}
          </DropdownMenu.Item>
        </DropdownMenu.Content>
      </DropdownMenu.Portal>

      {/*
        Outside the menu's portal content on purpose: a dialog rendered inside a dropdown is unmounted the moment
        the dropdown closes, which is the moment it opens.
      */}
      <PasswordDialog open={isChangingPassword} onOpenChange={setIsChangingPassword} />
    </DropdownMenu.Root>
  );
}
