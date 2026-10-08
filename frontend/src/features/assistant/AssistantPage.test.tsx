import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { AssistantPage } from './AssistantPage';
import type {
  AssistantAnswer,
  AssistantFinding,
  AssistantVocabulary,
  ReportProposal,
} from './types';

const permissions = vi.hoisted(() => ({
  current: ['analytics.view', 'reports.manage'] as string[],
}));

vi.mock('@/features/auth', () => ({
  PERMISSIONS: { analyticsView: 'analytics.view', reportsManage: 'reports.manage' },
  useSession: () => ({ can: (permission: string) => permissions.current.includes(permission) }),
}));

vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({
    isPending: false,
    data: { data: [{ id: 'client-1', name: 'Alhambra' }] },
  }),
}));

const state = vi.hoisted(() => ({
  vocabulary: null as AssistantVocabulary | null,
  analysis: null as AssistantAnswer | null,
  asked: null as string | null,
  askAnswer: null as AssistantAnswer | null,
  proposal: null as ReportProposal | null,
  investigated: null as string | null,
}));

vi.mock('./api', () => ({
  fetchAssistantVocabulary: () => Promise.resolve(state.vocabulary),
  fetchAnalysis: () => Promise.resolve(state.analysis),
  askAssistant: (input: { question: string }) => {
    state.asked = input.question;

    return Promise.resolve(state.askAnswer);
  },
  investigateFinding: (input: { code: string }) => {
    state.investigated = input.code;

    return Promise.resolve(state.analysis);
  },
  fetchReportProposal: () => Promise.resolve(state.proposal),
}));

function vocabulary(): AssistantVocabulary {
  return {
    modes: [
      {
        mode: 'analyse',
        label: 'Analyse',
        description: 'Everything the analysers can say.',
        permission: 'analytics.view',
        enacts_anything: false,
      },
    ],
    deferred: [
      {
        mode: 'plan',
        label: 'Plan',
        because: 'A media plan needs the objective, the budget and the flight dates.',
      },
    ],
    questions: [{ intent: 'spend', label: 'What was spent', example: 'How much did we spend?' }],
    severities: [{ severity: 'warning', label: 'Worth a decision', needs_attention: true }],
    confidence: [{ confidence: 'firm', label: 'Firm', supports_recommendation: true }],
    denominator_floor: 30,
  };
}

function finding(overrides: Partial<AssistantFinding> = {}): AssistantFinding {
  return {
    code: 'efficiency_drift.cpc',
    severity: 'warning',
    severity_label: 'Worth a decision',
    needs_attention: true,
    subject: 'The account',
    headline: 'Cost per click is worse than the period before.',
    detail: 'A move of this size is usually a change in what is being bid on.',
    confidence: 'firm',
    confidence_label: 'Firm',
    source: 'sandbox',
    source_label: 'Sandbox',
    recommendation: null,
    evidence: [
      {
        metric: 'cpc',
        label: 'Cost per click',
        value: 4.2,
        compared_with: 2.1,
        period: 'the last thirty days',
        source: 'sandbox',
        source_label: 'Sandbox',
        read_from: 'analytics totals',
        reason: null,
      },
    ],
    ...overrides,
  };
}

function answer(overrides: Partial<AssistantAnswer> = {}): AssistantAnswer {
  return {
    mode: 'analyse',
    client_name: 'Alhambra',
    period: { from: '2026-09-09', until: '2026-10-08', days: 30 },
    comparison_period: { from: '2026-08-10', until: '2026-09-08' },
    question: null,
    intent: null,
    source: 'sandbox',
    source_label: 'Sandbox',
    requires_label: true,
    needs_attention: 1,
    unanswered: 0,
    skipped: [],
    narrative: {
      text: 'The account paid more per click than before.',
      written_by: 'product',
      model: null,
      model_rejected: false,
      rejection_reason: null,
    },
    findings: [finding()],
    ...overrides,
  };
}

async function open(): Promise<void> {
  await renderWithProviders(<AssistantPage />);
  await userEvent.click(await screen.findByRole('button', { name: 'Alhambra' }));
}

beforeEach(() => {
  permissions.current = ['analytics.view', 'reports.manage'];
  state.vocabulary = vocabulary();
  state.analysis = answer();
  state.askAnswer = answer({ mode: 'ask', question: 'How much did we spend?', intent: 'spend' });
  state.asked = null;
  state.investigated = null;
  state.proposal = {
    analysis: answer(),
    proposal: {
      name: 'Alhambra — 30 days to 2026-10-08',
      description: 'Proposed by the assistant.',
      client_id: 'client-1',
      window: 'last_30_days',
      comparison: 'previous_period',
      audience: 'internal',
      sections: [{ kind: 'scorecard', metrics: ['spend', 'cpc'], dimension: null }],
    },
  };
});

describe('AssistantPage', () => {
  it('says what it does and does not do, before the answer', async () => {
    await renderWithProviders(<AssistantPage />);

    /*
     * This is the one surface where a reader's default assumption — that a model worked the figures out — is
     * exactly wrong, so the correction arrives before anything else on the page.
     */
    expect(await screen.findByText(/Every figure is computed by the product/)).toBeInTheDocument();
    expect(screen.getByText(/No rate is quoted from fewer than 30 events/)).toBeInTheDocument();
    expect(screen.getByText(/Nothing here changes anything/)).toBeInTheDocument();
  });

  it('requires a client rather than averaging across all of them', async () => {
    await renderWithProviders(<AssistantPage />);

    /* A cost per click averaged over unrelated accounts is a number with no owner. */
    expect(await screen.findByText('Pick a client')).toBeInTheDocument();
  });

  it('shows every figure with its period, its source and where it was read from', async () => {
    await open();

    const card = await screen.findByText('Cost per click is worse than the period before.');
    expect(card).toBeInTheDocument();

    /* Not behind a disclosure: a claim nobody can check in passing is a claim nobody checks. */
    expect(screen.getByText(/Cost per click:/)).toBeInTheDocument();
    expect(screen.getByText(/was 2.1/)).toBeInTheDocument();
    expect(screen.getByText(/from analytics totals/)).toBeInTheDocument();
  });

  it('says who wrote the prose', async () => {
    await open();

    expect(
      await screen.findByText(/Written by digitalSmart from the findings/),
    ).toBeInTheDocument();
  });

  it('says when a model answered', async () => {
    state.analysis = answer({
      narrative: {
        text: 'The account paid more per click than before.',
        written_by: 'model',
        model: 'claude-sonnet-5-5',
        model_rejected: false,
        rejection_reason: null,
      },
    });

    await open();

    expect(await screen.findByText(/Written by claude-sonnet-5-5/)).toBeInTheDocument();
  });

  it("shows that a model's answer was refused, rather than swallowing it", async () => {
    state.analysis = answer({
      narrative: {
        text: 'The account paid more per click than before.',
        written_by: 'product',
        model: 'claude-sonnet-5-5',
        model_rejected: true,
        rejection_reason: "The model's answer stated 4812.77, which the evidence does not contain.",
      },
    });

    await open();

    /* A guard nobody can see working is a guard nobody will maintain. */
    expect(await screen.findByText("The model's answer was refused")).toBeInTheDocument();
    expect(screen.getByText(/4812.77/)).toBeInTheDocument();
  });

  it('says when a finding carries no recommendation, and why', async () => {
    await open();

    /*
     * Most findings carry none. A reader who is not told why assumes the module had nothing to offer rather than
     * that the evidence was too thin to act on.
     */
    expect(
      await screen.findByText(/the evidence is not firm enough to act on alone/),
    ).toBeInTheDocument();
  });

  it("names the analysers a reader's permissions kept out of the answer", async () => {
    state.analysis = answer({ skipped: ['tracking_blind_spot'] });

    await open();

    /* An answer that quietly differed by role would leave the reader unaware a question went unasked. */
    expect(
      await screen.findByText(/Not looked at, because your permissions do not cover it/),
    ).toBeInTheDocument();
  });

  it('counts the questions it could not answer as its own figure', async () => {
    state.analysis = answer({ unanswered: 2 });

    await open();

    expect(
      await screen.findByText('2 questions could not be answered from this period'),
    ).toBeInTheDocument();
  });

  it('publishes the questions it understands and asks one on request', async () => {
    await open();

    await userEvent.click(screen.getByRole('button', { name: 'Ask' }));

    const understood = await screen.findByRole('region', { name: 'Questions it understands' });
    await userEvent.click(
      within(understood).getByRole('button', { name: 'How much did we spend?' }),
    );

    expect(state.asked).toBe('How much did we spend?');
  });

  it('publishes what it does not do yet, instead of offering an empty tab', async () => {
    await open();

    const deferred = await screen.findByRole('region', { name: 'Not built yet' });

    expect(within(deferred).getByText(/media plan needs the objective/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Plan' })).not.toBeInTheDocument();
  });

  it('proposes a report and says nothing has been saved', async () => {
    await open();

    await userEvent.click(screen.getByRole('button', { name: 'Report' }));

    expect(await screen.findByText(/Nothing has been saved/)).toBeInTheDocument();
    /* Internal, always: a client-safe report is one somebody has read as the client would. */
    expect(screen.getByText(/Proposed as internal/)).toBeInTheDocument();
  });

  it('offers no report tab to a reader who could not save one', async () => {
    permissions.current = ['analytics.view'];

    await open();

    await screen.findByRole('button', { name: 'Analyse' });
    expect(screen.queryByRole('button', { name: 'Report' })).not.toBeInTheDocument();
  });

  it('refuses the surface to a role without the permission', async () => {
    permissions.current = [];

    await renderWithProviders(<AssistantPage />);

    expect(await screen.findByText('The assistant is not part of your access')).toBeInTheDocument();
  });
});
