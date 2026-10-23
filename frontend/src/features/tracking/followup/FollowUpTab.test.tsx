import { screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { FollowUpTab } from './FollowUpTab';
import type { FollowUp } from './types';

const state: { data: FollowUp } = { data: undefined as unknown as FollowUp };
const save = vi.fn();

vi.mock('./api', () => ({
  useFollowUpQuery: () => ({
    isPending: false,
    isError: false,
    data: state.data,
    dataUpdatedAt: 1,
  }),
  useSaveAutomationMutation: () => ({
    isPending: false,
    isError: false,
    isSuccess: false,
    mutate: save,
  }),
}));
vi.mock('../health/ProjectScope', () => ({
  ProjectScope: ({ onChange }: { onChange: (v: { id: string; label: string }) => void }) => (
    <button type="button" onClick={() => onChange({ id: 'p1', label: 'Spring' })}>
      pick project
    </button>
  ),
}));
vi.mock('@/features/auth', () => ({
  PERMISSIONS: { trackingManage: 'tracking.manage' },
  useSession: () => ({ can: () => true }),
}));

const empty = { count: 0, items: [] };

function followUp(over: Partial<FollowUp['groups']> = {}): FollowUp {
  return {
    settings: {
      project_id: 'p1',
      enabled: true,
      assignment_mode: 'round_robin',
      owner_id: null,
      create_task: true,
      task_title_ar: null,
      task_title_en: null,
      task_priority: 'high',
      notify_owner: true,
      sla_minutes: 30,
    },
    owners: [{ id: 'u1', name: 'Noura' }],
    timezone: 'Asia/Riyadh',
    groups: {
      new: empty,
      approaching: empty,
      overdue: empty,
      attempted: empty,
      no_response: empty,
      qualified: empty,
      ...over,
    },
  };
}

describe('FollowUpTab', () => {
  beforeEach(() => {
    save.mockReset();
  });

  it('shows the overdue group first, with the state as a word and who holds each lead', async () => {
    state.data = followUp({
      overdue: {
        count: 1,
        items: [
          {
            id: 'l1',
            name: 'Prospect',
            source: 'Enquiry form',
            stage: 'new',
            owner: { id: 'u1', name: 'Noura' },
            assignment_reason: 'round_robin',
            received_at: '2026-10-23T08:00:00+00:00',
            first_attempt_at: null,
            first_contacted_at: null,
            sla_state: 'overdue',
            due_at: '2026-10-23T11:30:00+03:00',
            sla_met: false,
          },
        ],
      },
    });
    await renderWithProviders(<FollowUpTab />);
    (await screen.findByRole('button', { name: 'pick project' })).click();

    expect(await screen.findByText('Past the response time')).toBeInTheDocument();
    expect(screen.getByText('Overdue')).toBeInTheDocument();
    expect(screen.getByText(/With Noura/)).toBeInTheDocument();
    expect(screen.getByText(/rota/)).toBeInTheDocument();
  });

  it('says nobody is waiting rather than showing empty groups', async () => {
    state.data = followUp();
    await renderWithProviders(<FollowUpTab />);
    (await screen.findByRole('button', { name: 'pick project' })).click();

    expect(await screen.findByText('Nobody is waiting')).toBeInTheDocument();
    expect(screen.queryByText('Past the response time')).not.toBeInTheDocument();
  });

  it('offers a person only when the assignment names one', async () => {
    state.data = followUp();
    await renderWithProviders(<FollowUpTab />);
    (await screen.findByRole('button', { name: 'pick project' })).click();
    await screen.findByText('New leads');

    expect(screen.queryByLabelText('Person')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Give each new lead to')).toHaveValue('round_robin');
  });
});
