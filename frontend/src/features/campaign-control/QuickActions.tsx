import { useState, type FormEvent } from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ListPlus, FileText, PauseCircle, PlayCircle, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { useSession } from '@/features/auth';
import { api, ApiError } from '@/shared/api/client';
import { useUiStore } from '@/shared/store/ui-store';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Textarea } from '@/shared/ui/textarea';
import type { CampaignRow } from './types';

/**
 * What can be done to a campaign from its card — and only what this reader may do.
 *
 * Three actions: raise a task about the campaign, go to the reports narrowed to its client and project, and
 * **ask** for it to be paused or resumed. The last one never changes a campaign: it writes an approval request
 * the server builds from its own records, and it is offered only where the server says so (`status_action`) —
 * the platform's write documented, the campaign in a state the change fits, the reader allowed to ask. Deciding
 * and carrying out are two more acts by two more permissions, and the dialog says so before it is sent.
 */
export function QuickActions({ row }: { row: CampaignRow }) {
  const { t } = useTranslation();
  const { can } = useSession();
  const navigate = useNavigate();
  const setContext = useUiStore((s) => s.setContext);
  const [open, setOpen] = useState(false);

  const [asking, setAsking] = useState(false);

  const canTask = can('tasks.manage');
  const canReport = can('reports.view') && row.client_id !== null;
  const ask = row.status_action ?? null;

  if (!canTask && !canReport && ask === null) {
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
      {ask === null ? null : (
        <Button variant="ghost" size="sm" onClick={() => setAsking(true)}>
          {ask === 'pause' ? <PauseCircle aria-hidden /> : <PlayCircle aria-hidden />}
          {t(
            ask === 'pause'
              ? 'campaignControl.actions.askPause'
              : 'campaignControl.actions.askResume',
          )}
        </Button>
      )}
      {open ? <TaskDialog row={row} onClose={() => setOpen(false)} /> : null}
      {asking && ask !== null ? (
        <StatusRequestDialog row={row} action={ask} onClose={() => setAsking(false)} />
      ) : null}
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
        /* The page that opened this dialog reads the campaign's tasks and the project's tasks under these keys. */
        queryClient.invalidateQueries({ queryKey: ['campaign-detail'] }),
        queryClient.invalidateQueries({ queryKey: ['projects', 'workspace'] }),
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

/**
 * Ask for a status change. Nothing about the campaign changes when this is sent — the dialog says so, and says
 * what happens next, because "Pause" on a campaign card that only filed a request would otherwise be read as having
 * paused it.
 */
function StatusRequestDialog({
  row,
  action,
  onClose,
}: {
  row: CampaignRow;
  action: 'pause' | 'resume';
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [reason, setReason] = useState('');

  const send = useMutation({
    mutationFn: async () => {
      await api.post(`/campaigns/${row.id}/status-request`, { action, reason: reason.trim() });
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['approvals'] }),
        queryClient.invalidateQueries({ queryKey: ['attention'] }),
      ]);
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();

    if (reason.trim().length >= 3) {
      send.mutate();
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
                {t(
                  action === 'pause'
                    ? 'campaignControl.actions.askPause'
                    : 'campaignControl.actions.askResume',
                )}
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

          {send.isSuccess ? (
            <div className="flex flex-col gap-4">
              <Alert tone="info">{t('campaignControl.actions.requested')}</Alert>
              <div className="flex justify-end gap-2">
                <Link
                  to="/approvals"
                  onClick={onClose}
                  className="text-content-brand self-center text-sm font-medium hover:underline"
                >
                  {t('campaignControl.actions.openApprovals')}
                </Link>
                <Button onClick={onClose}>{t('actions.close')}</Button>
              </div>
            </div>
          ) : (
            <form onSubmit={submit} className="flex flex-col gap-4">
              <p className="text-content-secondary text-sm">
                {t('campaignControl.actions.askExplain')}
              </p>
              {send.isError ? (
                <Alert tone="danger">
                  {send.error instanceof ApiError
                    ? send.error.message
                    : t('campaignControl.actions.failed')}
                </Alert>
              ) : null}
              <Field label={t('campaignControl.actions.why')} required>
                {(props) => (
                  <Textarea
                    {...props}
                    rows={3}
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    dir="auto"
                  />
                )}
              </Field>
              <div className="flex justify-end gap-2">
                <Button type="button" variant="ghost" onClick={onClose}>
                  {t('actions.cancel')}
                </Button>
                <Button type="submit" disabled={send.isPending || reason.trim().length < 3}>
                  {t('campaignControl.actions.sendRequest')}
                </Button>
              </div>
            </form>
          )}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
