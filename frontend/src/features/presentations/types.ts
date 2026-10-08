/**
 * Decks, as the API publishes them.
 *
 * Three shapes carry the module's argument rather than its data:
 *
 *  - **`theme`** travels with the deck. The page draws from these tokens rather than from a stylesheet of its
 *    own, so the preview and the exported file change together — and `is_provisional` is true until Smart Code's
 *    reference deck exists, which the surface says rather than hides.
 *  - **Every figure is already a string.** Nothing here formats a number: the server formatted it once, so the
 *    page and the PowerPoint file cannot disagree about a rounding.
 *  - **`points` keeps nulls.** A day with no delivery is a gap, and a chart that drew it as zero would put a line
 *    through the floor of a slide somebody presents to a client.
 */

export type DeckKindValue = 'weekly_report' | 'monthly_report' | 'closing_report' | 'media_plan';

export type SlideKindValue =
  'title' | 'scorecard' | 'trend' | 'breakdown' | 'table' | 'caveat' | 'commentary';

export type DeckKindInfo = {
  kind: DeckKindValue;
  label: string;
  description: string;
  window: string | null;
  compares_periods: boolean;
  /** True for the media plan, which is composed from a budget rather than from delivery. */
  built_from_budget: boolean;
};

export type DeferredDeckInfo = {
  kind: string;
  label: string;
  because: string;
};

export type PresentationVocabulary = {
  kinds: DeckKindInfo[];
  deferred: DeferredDeckInfo[];
  slide_kinds: { kind: SlideKindValue; label: string; draws_chart: boolean }[];
};

export type DeckTheme = {
  name: string;
  /** True while the identity is a placeholder rather than somebody's brand. */
  is_provisional: boolean;
  slide_width_pt: number;
  slide_height_pt: number;
  canvas: string;
  ink: string;
  ink_muted: string;
  accent: string;
  accent_contrast: string;
  line: string;
  latin_font: string;
  arabic_font: string;
  title_size: number;
  heading_size: number;
  body_size: number;
  caption_size: number;
  series: string[];
};

export type DeckFigure = {
  label: string;
  /** Formatted by the server. See the note above. */
  value: string;
  change: string | null;
  change_is_good: boolean | null;
  source_label: string;
  unavailable_reason: string | null;
};

export type DeckSeries = {
  name: string;
  points: (number | null)[];
  labels: string[];
};

export type DeckSlide = {
  kind: SlideKindValue;
  title: string;
  lead: string | null;
  figures: DeckFigure[];
  series: DeckSeries[];
  categories: string[];
  table: { columns: string[]; rows: { label: string; cells: string[] }[] } | null;
  notes: string[];
  paragraphs: string[];
};

export type Deck = {
  kind: DeckKindValue;
  kind_label: string;
  client_name: string;
  title: string;
  subtitle: string;
  source: string;
  source_label: string;
  requires_label: boolean;
  prepared_at: string;
  is_client_safe: boolean;
  theme: DeckTheme;
  slides: DeckSlide[];
};
