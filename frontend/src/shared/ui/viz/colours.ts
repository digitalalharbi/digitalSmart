/** The semantic colour of each health word, as design tokens: one meaning, one colour, in every chart. */
export const HEALTH_COLOUR = {
  healthy: 'var(--ds-success)',
  watch: 'var(--ds-chart-3)',
  needs_attention: 'var(--ds-accent)',
  critical: 'var(--ds-danger)',
  insufficient_data: 'var(--ds-border-strong)',
  inactive: 'var(--ds-surface-active)',
} as const;
