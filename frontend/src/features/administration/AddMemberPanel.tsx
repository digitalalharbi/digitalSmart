import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { UserPlus } from 'lucide-react';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { useInviteMemberMutation } from './api';
import { WORKSPACE_ROLES, type InvitedMember, type WorkspaceRole } from './types';

/**
 * Add a colleague to the workspace.
 *
 * **No invitation email, and the panel says so rather than implying one is on its way.** This deployment has no
 * sender identity, so what it does instead is hand the administrator a one-time password to convey — and that is
 * only acceptable because it stops working the moment the member replaces it, which every other request refuses
 * to proceed without.
 *
 * **The password is shown once, and the panel says that too.** Nothing stores it: if the administrator loses it
 * before passing it on, the honest remedy is to add nothing and set a new one, because there is nowhere to look
 * it up.
 *
 * **Owner is not offered.** An owner can remove other owners, so creating one is a decision for somebody
 * already in that position — the role control on the member list is where it happens, with the last-owner
 * protection around it.
 */
export function AddMemberPanel() {
  const { t } = useTranslation();
  const invite = useInviteMemberMutation();

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [role, setRole] = useState<WorkspaceRole>('media-buyer');
  const [added, setAdded] = useState<InvitedMember | null>(null);

  /* Owner is deliberately absent — see the note above. */
  const offered = WORKSPACE_ROLES.filter((candidate) => candidate !== 'owner');

  return (
    <section className="border-line-subtle flex flex-col gap-4 rounded-xl border p-5">
      <div className="flex flex-col gap-1">
        <h3 className="text-content flex items-center gap-2 text-sm font-semibold">
          <UserPlus aria-hidden className="size-4" />
          {t('administration.addMember.title')}
        </h3>
        <p className="text-content-secondary text-sm">{t('administration.addMember.subtitle')}</p>
      </div>

      {added === null ? null : (
        <Alert tone={added.is_existing_account ? 'info' : 'warning'}>
          {added.is_existing_account
            ? t('administration.addMember.joined', { name: added.name })
            : t('administration.addMember.oneTime', {
                name: added.name,
                password: added.one_time_password,
              })}
        </Alert>
      )}

      {invite.error === null ? null : (
        <Alert tone="danger">
          {invite.error instanceof ApiError && invite.error.message !== ''
            ? invite.error.message
            : t('administration.addMember.failed')}
        </Alert>
      )}

      <form
        className="grid gap-3 sm:grid-cols-2"
        onSubmit={(event) => {
          event.preventDefault();

          if (name.trim() === '' || email.trim() === '') {
            return;
          }

          invite.mutate(
            { name: name.trim(), email: email.trim(), role },
            {
              onSuccess: (result) => {
                setAdded(result);
                setName('');
                setEmail('');
              },
            },
          );
        }}
      >
        <Field label={t('administration.addMember.name')} required>
          {(props) => (
            <Input
              {...props}
              value={name}
              onChange={(event) => setName(event.target.value)}
              required
            />
          )}
        </Field>

        <Field
          label={t('administration.addMember.email')}
          hint={t('administration.addMember.emailHint')}
          required
        >
          {(props) => (
            <Input
              {...props}
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              required
            />
          )}
        </Field>

        <Field label={t('administration.addMember.role')} required>
          {(props) => (
            <select
              {...props}
              className="border-line bg-surface text-content rounded-lg border px-3 py-2 text-sm"
              value={role}
              onChange={(event) => setRole(event.target.value as WorkspaceRole)}
            >
              {offered.map((candidate) => (
                <option key={candidate} value={candidate}>
                  {t(`administration.roles.${candidate}`)}
                </option>
              ))}
            </select>
          )}
        </Field>

        <div className="flex items-end">
          <Button type="submit" disabled={invite.isPending}>
            {invite.isPending
              ? t('administration.addMember.adding')
              : t('administration.addMember.submit')}
          </Button>
        </div>
      </form>
    </section>
  );
}
