import type { Locator, Page } from '@playwright/test';

/**
 * Choose a client in a search-picker.
 *
 * The product no longer lists clients as a row of buttons or a `<select>`: at a hundred clients that is
 * unusable, and past a hundred it silently drops the rest. A picker is a search box, so a test types and picks.
 * `DEMO` is the prefix every demo client carries, which is what makes "the first client" well defined.
 */
export async function chooseClient(
  scope: Page | Locator,
  term = 'DE',
  optionName?: string,
): Promise<void> {
  const box = scope.getByRole('combobox', { name: 'Client' });

  await box.fill(term);

  /* The picker's own listbox, not any `<option>` in the form around it. */
  const options = scope.getByRole('listbox', { name: 'Client' }).getByRole('option');

  await (
    optionName === undefined ? options.first() : options.filter({ hasText: optionName }).first()
  ).click();
}
