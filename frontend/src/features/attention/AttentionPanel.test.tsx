import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { ApiError } from '@/shared/api/client';
import { AttentionPanel } from './AttentionPanel';
import type { AttentionItem, AttentionQueue } from './types';

const query = vi.hoisted(() => vi.fn());

vi.mock('./useAttention', () => ({ useAttentionQuery: () => query() }));

function item(overrides: Partial<AttentionItem> = {}): AttentionItem {
  return {
    id: 'task:t1',
    stream: 'tasks',
    stream_label: 'tasks',
    severity: 'needs_attention',
    severity_label: 'Needs attention',
    message: 'Overdue: Ship the Ramadan creative',
    entity: { kind: 'Task', label: 'Alhambra Retail' },
    owner: 'Layla Nasser',
    since: '2026-06-01T09:00:00Z',
    client_id: 'c1',
    client_name: 'Alhambra Retail',
    project_id: null,
    project_name: null,
    action: { path: '/tasks' },
    ...overrides,
  };
}

function queue(overrides: Partial<AttentionQueue['meta']> = {}, items: AttentionItem[] = [item()]) {
  const data: AttentionQueue = {
    data: items,
    meta: {
      severity_counts: { critical: 0, needs_attention: items.length, watch: 0 },
      total: items.length,
      covered: [
        { stream: 'budgets', label: 'budgets' },
        { stream: 'tracking', label: 'tracking' },
        { stream: 'tasks', label: 'tasks' },
      ],
      failed: [],
      as_of: '2026-06-15T12:00:00Z',
      severities: [],
      streams: [
        { stream: 'tasks', label: 'tasks', path: '/tasks' },
        { stream: 'budgets', label: 'budgets', path: '/budget-calculator' },
      ],
      ...overrides,
    },
  };

  return { data, isPending: false, isError: false, error: null };
}

describe('AttentionPanel', () => {
  beforeEach(() => {
    query.mockReset();
    query.mockReturnValue(queue());
  });

  it('shows each item with its sentence, its entity and one action', async () => {
    await renderWithProviders(<AttentionPanel />);

    const list = screen.getByRole('list', { name: 'Needs attention' });
    expect(within(list).getByText('Overdue: Ship the Ramadan creative')).toBeInTheDocument();
    expect(within(list).getByText('Alhambra Retail')).toBeInTheDocument();
    expect(within(list).getByRole('link', { name: /tasks/ })).toHaveAttribute('href', '/tasks');
  });

  /*
   * "Nothing needs attention" means something different to a finance manager than to a creative lead.
   * The scope is what makes the claim honest, and the server is what decides it.
   */
  it('names the streams the server actually looked at', async () => {
    await renderWithProviders(<AttentionPanel />);

    expect(screen.getByText('Across budgets, tracking, and tasks.')).toBeInTheDocument();
  });

  it('renders nothing at all for a role that can see none of the streams', async () => {
    query.mockReturnValue(queue({ covered: [], total: 0 }, []));
    const { container } = await renderWithProviders(<AttentionPanel />);

    expect(container).toBeEmptyDOMElement();
  });

  /*
   * Without this, a stream that failed and a quiet week render identically — and the broken one renders
   * as the reassuring one.
   */
  it('says the list is incomplete when a stream could not be read', async () => {
    query.mockReturnValue(queue({ failed: ['budgets'] }));
    await renderWithProviders(<AttentionPanel />);

    expect(screen.getByRole('alert')).toHaveTextContent(
      'This list is incomplete: budgets could not be read just now.',
    );
  });

  it('counts the whole queue, not the page it is showing', async () => {
    query.mockReturnValue(queue({ total: 23 }));
    await renderWithProviders(<AttentionPanel />);

    expect(screen.getByText('23 items')).toBeInTheDocument();
  });

  it('reddens the count when something is critical', async () => {
    query.mockReturnValue(
      queue({ severity_counts: { critical: 1, needs_attention: 0, watch: 0 }, total: 1 }, [
        item({ severity: 'critical' }),
      ]),
    );
    await renderWithProviders(<AttentionPanel />);

    expect(screen.getByRole('img', { name: 'Critical' })).toBeInTheDocument();
  });

  /*
   * Absent and null are different facts on the wire. A budget has no assignee at all, and printing
   * "Unassigned" against one would invent a gap that never existed.
   */
  it('prints no owner line for a kind that has none, and "unassigned" for one that does', async () => {
    const budget = item({
      id: 'budget:b1',
      stream: 'budgets',
      entity: { kind: 'Budget', label: 'Q2' },
    });
    delete budget.owner;

    query.mockReturnValue(queue({}, [budget, item({ id: 'task:t2', owner: null })]));
    await renderWithProviders(<AttentionPanel />);

    expect(screen.getAllByText('Unassigned')).toHaveLength(1);
  });

  it('says so when the queue itself could not be loaded', async () => {
    query.mockReturnValue({
      data: undefined,
      isPending: false,
      isError: true,
      error: new ApiError('', 500),
    });
    await renderWithProviders(<AttentionPanel />);

    expect(screen.getByRole('alert')).toHaveTextContent(
      'What needs attention could not be loaded.',
    );
  });

  it('groups by area from the one response, and narrows the list to the area chosen', async () => {
    query.mockReturnValue(
      queue({}, [
        item({
          id: 'campaign:1',
          stream: 'campaigns',
          message: 'Ramadan Push has spent with nothing recorded',
        }),
        item({ id: 'campaign:2', stream: 'campaigns', message: 'Eid Push is delivering far less' }),
        item({ id: 'task:1', stream: 'tasks', message: 'Overdue: Ship the creative' }),
      ]),
    );
    const user = userEvent.setup();
    await renderWithProviders(<AttentionPanel />);

    await user.click(screen.getByRole('button', { name: '2 campaigns need attention' }));

    expect(screen.getByText('Ramadan Push has spent with nothing recorded')).toBeInTheDocument();
    expect(screen.queryByText('Overdue: Ship the creative')).not.toBeInTheDocument();
  });
});
