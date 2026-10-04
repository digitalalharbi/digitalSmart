import type { Page } from '@playwright/test';

/**
 * Demo accounts created by DemoWorkspaceSeeder.
 *
 * The suite exercises real roles rather than a single superuser, so a
 * permission regression fails here as well as in the backend tests.
 */
export const ACCOUNTS = {
  owner: 'owner@demo.digitalsmart.test',
  manager: 'manager@demo.digitalsmart.test',
  buyer: 'buyer@demo.digitalsmart.test',
  creative: 'creative@demo.digitalsmart.test',
  client: 'client@demo.digitalsmart.test',
} as const;

export const DEMO_PASSWORD = 'password';

export async function signIn(page: Page, email: string): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password').fill(DEMO_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}
