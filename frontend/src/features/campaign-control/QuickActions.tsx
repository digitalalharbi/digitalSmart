import { useState, type FormEvent } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ListPlus, FileText, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { useSession } from '@/features/auth';
import { api, ApiError } from '@/shared/api/client';
import { useUiStore } from '@/shared/store/ui-store';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import type { CampaignRow } from './types';

/**
 * What can be done to a campaign from its card — and only what this reader may do.
 *
 * Two actions, both of which this product performs itself: raise a task about the campaign, and go to the
 * reports narrowed to its client and project. Changing a *live* campaign's status is deliberately absent:
 * it needs a provider whose write capability is documented, a permission, a sandbox-aware approval and the
 * approval's own carry-out step, and an action offered without all four is a control that cannot honestly
 * work. It will appear here when the approvals flow can carry it out, not before.
 */
export function QuickActions({ row }: { row: CampaignRow }) {
  const { t } = useTranslation();
  const { can } = useSession();
  const navigate = useNavigate();
  const setContext = useUiStore((s) => s.setContext);
  const [open, setOpen] = useState(false);

  const canTask = can('tasks.manage');
  const canReport = can('reports.view') && row.client_id !== null;

  if (!canTask && !canReport) {
    return null;
  }

  return (
    <div
      className="flex flex-wrap gap-2"
      role="group"
      aria-label={t('campaignControl.actions.label')}
    >
      {canTask ? (
        <Button variant="ghost" size="sm" onClick={() => setOpen(true)}>
          <ListPlus aria-hidden />
          {t('campaignControl.actions.task')}
        </Button>
      ) : null}
      {canReport ? (
        <Button
          variant="ghost"
          size="sm"
          onClick={() => {
            setContext({
              client:
                row.client_id === null ? null : { id: row.client_id, label: row.client_name ?? '' },
              project:
                row.project_id === null
                  ? null
                  : { id: row.project_id, label: row.project_name ?? '' },
            });
            void navigate('/reports');
          }}
        >
          <FileText aria-hidden />
          {t('campaignControl.actions.report')}
        </Button>
      ) : null}
      {open ? <TaskDialog row={row} onClose={() => setOpen(false)} /> : null}
    </div>
  );
}

function TaskDialog({ row, onClose }: { row: CampaignRow; onClose: () => void }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [title, setTitle] = useState(t('campaignControl.actions.defaultTitle', { name: row.name }));
  const [due, setDue] = useState('');

  const create = useMutation({
    mutationFn: async () => {
      await api.post('/tasks', {
        title: title.trim(),
        priority: row.needs_attention ? 'high' : 'normal',
        ...(row.client_id === null ? {} : { client_id: row.client_id }),
        ...(row.project_id === null ? {} : { project_id: row.project_id }),
        ...(due === '' ? {} : { due_on: due }),
        subject_type: 'campaign',
        subject_id: row.id,
      });
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['tasks'] }),
        queryClient.invalidateQueries({ queryKey: ['attention'] }),
      ]);
      onClose();
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();

    if (title.trim() !== '') {
      create.mutate();
    }
  };

  return (
    <Dialog.Root open onOpenChange={(next) => (next ? undefined : onClose())}>
      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40" />
        <Dialog.Content className="bg-surface fixed start-1/2 top-1/2 z-50 w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-xl p-6 shadow-lg rtl:translate-x-1/2">
          <div className="mb-4 flex items-start justify-between gap-4">
            <div>
              <Dialog.Title className="text-content text-lg font-semibold">
                {t('campaignControl.actions.task')}
              </Dialog.Title>
              <Dialog.Description className="text-content-secondary mt-1 text-sm" dir="auto">
                {row.name}
              </Dialog.Description>
            </div>
            <Dialog.Close asChild>
              <Button variant="ghost" size="icon" aria-label={t('actions.close')}>
                <X />
              </Button>
            </Dialog.Close>
          </div>
          <form onSubmit={submit} className="flex flex-col gap-4">
            {create.isError ? (
              <Alert tone="danger">
                {create.error instanceof ApiError
                  ? create.error.message
                  : t('campaignControl.actions.failed')}
              </Alert>
            ) : null}
            <Field label={t('campaignControl.actions.taskTitle')} required>
              {(props) => (
                <Input
                  {...props}
                  value={title}
                  onChange={(event) => setTitle(event.target.value)}
                  dir="auto"
                />
              )}
            </Field>
            <Field label={t('campaignControl.actions.due')}>
              {(props) => (
                <Input
                  {...props}
                  type="date"
                  value={due}
                  onChange={(event) => setDue(event.target.value)}
                />
              )}
            </Field>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="ghost" onClick={onClose}>
                {t('actions.cancel')}
              </Button>
              <Button type="submit" disabled={create.isPending || title.trim() === ''}>
                {t('campaignControl.actions.create')}
              </Button>
            </div>
          </form>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
