import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { ContextInsight } from './ContextInsight';

const analysis = vi.hoisted(() => vi.fn());

vi.mock('./useAssistant', () => ({ useAnalysisQuery: () => analysis() }));

const finding = (over: Record<string, unknown> = {}) => ({
  code: 'efficiency_drift',
  severity: 'warning',
  severity_label: 'Worth a look',
  needs_attention: true,
  subject: 'Alhambra',
  headline: 'Cost per lead is up 31%',
  detail: '',
  confidence: 'tentative',
  confidence_label: 'Tentative',
  source: 'sandbox',
  source_label: 'Sandbox',
  recommendation: null,
  evidence: [
    {
      metric: 'cpl',
      label: 'Cost per lead',
      value: 52,
      compared_with: 40,
      period: '',
      source: 'sandbox',
      source_label: 'Sandbox',
      read_from: '',
      reason: null,
    },
  ],
  ...over,
});

describe('ContextInsight', () => {
  beforeEach(() => {
    analysis.mockReset();
  });

  it('shows the issue, the figures behind it and how sure it is', async () => {
    analysis.mockReturnValue({ isPending: false, isError: false, data: { findings: [finding()] } });
    await renderWithProviders(<ContextInsight clientId="c1" clientName="Alhambra" />);

    expect(screen.getByText('Cost per lead is up 31%')).toBeInTheDocument();
    expect(screen.getByText('52')).toBeInTheDocument();
    expect(screen.getByText(/Confidence: Tentative/)).toBeInTheDocument();
  });

  it('says there is no recommendation rather than guessing one', async () => {
    analysis.mockReturnValue({ isPending: false, isError: false, data: { findings: [finding()] } });
    await renderWithProviders(<ContextInsight clientId="c1" clientName="Alhambra" />);

    expect(screen.getByText(/No recommendation/)).toBeInTheDocument();
  });

  it('shows a recommendation only when the server gave one', async () => {
    analysis.mockReturnValue({
      isPending: false,
      isError: false,
      data: { findings: [finding({ recommendation: 'Review the audience exclusions.' })] },
    });
    await renderWithProviders(<ContextInsight clientId="c1" clientName="Alhambra" />);

    expect(screen.getByText('Review the audience exclusions.')).toBeInTheDocument();
  });

  it('shows nothing when nothing needs attention, or when the reader may not run it', async () => {
    analysis.mockReturnValue({
      isPending: false,
      isError: false,
      data: { findings: [finding({ needs_attention: false })] },
    });
    const { container } = await renderWithProviders(<ContextInsight clientId="c1" />);
    expect(container).toBeEmptyDOMElement();

    analysis.mockReturnValue({ isPending: false, isError: true, data: undefined });
    const second = await renderWithProviders(<ContextInsight clientId="c1" />);
    expect(second.container).toBeEmptyDOMElement();
  });
});
