import type { AccessInbox, AccessRequestRow } from './types';

export function row(overrides: Partial<AccessRequestRow> = {}): AccessRequestRow {
  return {
    id: 'r1',
    kind: 'account_access',
    name: 'Noura Al-Harbi',
    email: 'noura@example.test',
    company: 'Alhambra',
    phone: '0555001122',
    job_title: null,
    preferred_contact: 'whatsapp',
    need: 'agency_clients',
    message: 'We run eight accounts.',
    locale: 'ar',
    status: 'new',
    created_at: new Date(Date.now() - 600_000).toISOString(),
    owner_id: null,
    owner_name: null,
    first_attempt_at: null,
    first_contacted_at: null,
    last_contact_at: null,
    next_follow_up_at: null,
    possible_duplicate: false,
    possible_duplicate_of: null,
    reviewed_at: null,
    reviewed_by_name: null,
    review_note: null,
    client_message: null,
    invited_at: null,
    invitation_shared_at: null,
    invitation_opened_at: null,
    accepted_at: null,
    account_created: false,
    first_login_at: null,
    sla: { state: 'within', due_at: new Date(Date.now() + 3_000_000).toISOString(), met: null },
    ...overrides,
  };
}

export function inbox(rows: AccessRequestRow[]): {
  data: AccessRequestRow[];
  meta: Omit<AccessInbox, 'rows'>;
} {
  return {
    data: rows,
    meta: {
      counts: {
        new: rows.filter((r) => r.status === 'new').length,
        contacted: rows.filter((r) => r.status === 'contacted').length,
        waiting_for_reply: 0,
        qualified: 0,
        approved: rows.filter((r) => r.status === 'approved').length,
        rejected: 0,
        closed: 0,
      },
      settings: { response_minutes: 60, default_owner_id: null, default_owner_name: null },
      owners: [{ id: 'u1', name: 'Sara' }],
      delivery: {
        email: { state: 'awaiting_credentials' },
        whatsapp: { state: 'awaiting_credentials' },
      },
    },
  };
}
