export type LogoVariant = 'auto' | 'dark' | 'light' | 'on-orange' | 'mono';
export type LogoLayout = 'horizontal' | 'stacked' | 'mark';

/** Minimum widths from the brand rules: 100px for the horizontal and stacked lockups, 16px for the mark. */
export const LOGO_MINIMUM: Record<LogoLayout, number> = {
  horizontal: 100,
  stacked: 100,
  mark: 16,
};
