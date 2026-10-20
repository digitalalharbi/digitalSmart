import type { Page } from '@playwright/test';

/**
 * Lists open on cards (clients, projects, rules); the table is one click away. A test that is about a table's rows
 * — comparing, sorting, a row's menu — asks for it here rather than leaning on which view happens to be the default.
 */
export async function tableView(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Table', exact: true }).click();
}
