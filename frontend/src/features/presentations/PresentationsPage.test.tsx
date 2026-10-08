import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { PresentationsPage } from './PresentationsPage';
import type { Deck, DeckSlide, DeckTheme, PresentationVocabulary } from './types';

const permissions = vi.hoisted(() => ({ current: ['reports.view'] as string[] }));

vi.mock('@/features/auth', () => ({
  PERMISSIONS: { reportsView: 'reports.view' },
  useSession: () => ({ can: (permission: string) => permissions.current.includes(permission) }),
}));

vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({
    isPending: false,
    data: { data: [{ id: 'client-1', name: 'Alhambra' }] },
  }),
}));

vi.mock('@/features/budgets', () => ({
  useBudgetsQuery: () => ({
    isPending: false,
    data: [{ id: 'budget-1', client_name: 'Alhambra', name: 'Q4 retail' }],
  }),
}));

vi.mock('@/shared/ui/chart', () => ({
  Chart: ({ ariaLabel }: { ariaLabel: string }) => <div data-testid="chart">{ariaLabel}</div>,
}));

const state = vi.hoisted(() => ({
  vocabulary: null as PresentationVocabulary | null,
  deck: null as Deck | null,
  asked: null as { kind: string; clientId: string | null; budgetId: string | null } | null,
}));

vi.mock('./api', () => ({
  fetchPresentationVocabulary: () => Promise.resolve(state.vocabulary),
  fetchDeck: (input: { kind: string; clientId: string | null; budgetId: string | null }) => {
    state.asked = input;

    return Promise.resolve(state.deck);
  },
  pptxUrl: (input: { kind: string }) => `/api/presentations/${input.kind}/pptx`,
}));

function theme(): DeckTheme {
  return {
    name: 'digitalSmart (provisional)',
    is_provisional: true,
    slide_width_pt: 960,
    slide_height_pt: 540,
    canvas: 'FFFFFF',
    ink: '111827',
    ink_muted: '6B7280',
    accent: '2563EB',
    accent_contrast: 'FFFFFF',
    line: 'E5E7EB',
    latin_font: 'Inter',
    arabic_font: 'IBM Plex Sans Arabic',
    title_size: 40,
    heading_size: 24,
    body_size: 14,
    caption_size: 10,
    series: ['2563EB', '059669'],
  };
}

function vocabulary(): PresentationVocabulary {
  return {
    kinds: [
      {
        kind: 'monthly_report',
        label: 'Monthly report',
        description: 'Last calendar month, against the month before.',
        window: 'last_month',
        compares_periods: true,
        built_from_budget: false,
      },
      {
        kind: 'media_plan',
        label: 'Media plan',
        description: 'What a budget allocates, by platform.',
        window: null,
        compares_periods: false,
        built_from_budget: true,
      },
    ],
    deferred: [
      {
        kind: 'campaign_proposal',
        label: 'Campaign proposal',
        because: 'A proposal is a document an agency writes. What is missing is the brief.',
      },
    ],
    slide_kinds: [{ kind: 'scorecard', label: 'Headline figures', draws_chart: false }],
  };
}

function slide(overrides: Partial<DeckSlide> = {}): DeckSlide {
  return {
    kind: 'scorecard',
    title: 'Headline figures',
    lead: null,
    figures: [
      {
        label: 'Spend',
        value: 'SAR 1,250.00',
        change: '+25.0%',
        change_is_good: false,
        source_label: 'Sandbox',
        unavailable_reason: null,
      },
    ],
    series: [],
    categories: [],
    table: null,
    notes: ['Figures: Sandbox.'],
    paragraphs: [],
    ...overrides,
  };
}

function deck(overrides: Partial<Deck> = {}): Deck {
  return {
    kind: 'monthly_report',
    kind_label: 'Monthly report',
    client_name: 'Alhambra',
    title: 'Alhambra — Monthly report',
    subtitle: '2026-09-01 to 2026-09-30',
    source: 'sandbox',
    source_label: 'Sandbox',
    requires_label: true,
    prepared_at: '2026-10-08T09:00:00+00:00',
    is_client_safe: true,
    theme: theme(),
    slides: [
      slide({
        kind: 'title',
        title: 'Alhambra — Monthly report',
        lead: '2026-09-01 to 2026-09-30',
        figures: [],
      }),
      slide(),
      slide({
        kind: 'caveat',
        title: 'What these figures do not say',
        lead: 'Read out, not skipped.',
        figures: [],
        paragraphs: ['The weakest figure in this deck is Sandbox.'],
      }),
    ],
    ...overrides,
  };
}

beforeEach(() => {
  permissions.current = ['reports.view'];
  state.vocabulary = vocabulary();
  state.deck = deck();
  state.asked = null;
});

describe('PresentationsPage', () => {
  it('needs a client before it builds anything', async () => {
    await renderWithProviders(<PresentationsPage />);

    expect(await screen.findByText('Pick a client')).toBeInTheDocument();
    expect(state.asked).toBeNull();
  });

  it('renders every slide the deck carries', async () => {
    await renderWithProviders(<PresentationsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Alhambra' }));

    const deckRegion = await screen.findByRole('region', { name: 'Alhambra — Monthly report' });
    /* One article per slide, each labelled with its position so a reader can refer to it. */
    expect(within(deckRegion).getAllByRole('article')).toHaveLength(3);
    expect(within(deckRegion).getByLabelText('Slide 1 of 3')).toBeInTheDocument();
  });

  it('shows the caveats as a slide', async () => {
    await renderWithProviders(<PresentationsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Alhambra' }));

    /* In a room, read out loud: a footnote in six-point type is a footnote the projector crops. */
    expect(await screen.findByText('What these figures do not say')).toBeInTheDocument();
    expect(screen.getByText('Read out, not skipped.')).toBeInTheDocument();
  });

  it('shows a figure with its change and its source, already formatted', async () => {
    await renderWithProviders(<PresentationsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Alhambra' }));

    /* Formatted by the server, so the slide and the PowerPoint file cannot disagree about a rounding. */
    expect(await screen.findByText('SAR 1,250.00')).toBeInTheDocument();
    expect(screen.getByText('+25.0% · Sandbox')).toBeInTheDocument();
  });

  it('says the theme is a placeholder', async () => {
    await renderWithProviders(<PresentationsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Alhambra' }));

    /* A placeholder presented as an agency's brand is worse than an obvious placeholder. */
    expect(
      await screen.findByText(/presentation identity has not been provided yet/),
    ).toBeInTheDocument();
  });

  it('explains why the PDF comes from the browser', async () => {
    await renderWithProviders(<PresentationsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Alhambra' }));

    expect(await screen.findByRole('button', { name: /Save as PDF/ })).toBeInTheDocument();
    expect(screen.getByText(/a second renderer to disagree with this one/)).toBeInTheDocument();
  });

  it('offers PowerPoint as a plain link', async () => {
    await renderWithProviders(<PresentationsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Alhambra' }));

    /* The browser downloads it, rather than JavaScript holding a deck in memory to hand back to itself. */
    const link = await screen.findByRole('link', { name: /Download PowerPoint/ });
    expect(link).toHaveAttribute('href', '/api/presentations/monthly_report/pptx');
  });

  it('asks for a budget when the deck is a media plan', async () => {
    await renderWithProviders(<PresentationsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Media plan' }));

    /* A media plan presents an allocation somebody made; a client id would build the wrong document. */
    expect(await screen.findByText('Pick a budget')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /Q4 retail/ }));

    expect(state.asked).toEqual({ kind: 'media_plan', clientId: null, budgetId: 'budget-1' });
  });

  it('publishes the deck it will not compose, with the reason', async () => {
    await renderWithProviders(<PresentationsPage />);

    await userEvent.click(await screen.findByRole('button', { name: 'Alhambra' }));

    const deferred = await screen.findByRole('region', { name: 'Not composed from this data' });

    expect(within(deferred).getByText(/What is missing is the brief/)).toBeInTheDocument();
    /* And it is not offered as a deck kind. */
    expect(screen.queryByRole('button', { name: 'Campaign proposal' })).not.toBeInTheDocument();
  });

  it('refuses the surface to a role without the permission', async () => {
    permissions.current = [];

    await renderWithProviders(<PresentationsPage />);

    expect(
      await screen.findByText('Presentations are not part of your access'),
    ).toBeInTheDocument();
  });
});
