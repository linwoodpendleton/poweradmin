/**
 * Authentication helper functions for Playwright tests
 *
 * These functions provide reusable authentication utilities
 * for Poweradmin E2E tests, equivalent to Cypress custom commands.
 */

/**
 * Login to Poweradmin via UI form (used by login tests that test the form itself)
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} username - Username for login
 * @param {string} password - Password for login
 * @returns {Promise<void>}
 */
export async function login(page, username, password) {
  await page.goto('/login');
  await page.fill('[data-testid="username-input"]', username);
  await page.fill('[data-testid="password-input"]', password);
  await page.click('[data-testid="login-button"]');
}

/**
 * Login and wait for dashboard with retry logic.
 *
 * Fills the login form and waits for the redirect concurrently using
 * Promise.all, which is faster than sequential wait.
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @param {string} username - Username for login
 * @param {string} password - Password for login
 * @param {number} maxRetries - Maximum number of retry attempts
 * @returns {Promise<void>}
 */
export async function loginAndWaitForDashboard(page, username, password, maxRetries = 3) {
  for (let attempt = 1; attempt <= maxRetries; attempt++) {
    try {
      await page.goto('/login');
      await page.fill('[data-testid="username-input"]', username);
      await page.fill('[data-testid="password-input"]', password);
      await Promise.all([
        page.waitForURL(url => !url.toString().includes('/login'), { timeout: 10000 }),
        page.click('[data-testid="login-button"]'),
      ]);
      return; // Success
    } catch {
      if (attempt === maxRetries) {
        throw new Error(`Login failed after ${maxRetries} attempts for user: ${username}`);
      }
      await page.waitForTimeout(1000 * attempt);
    }
  }
}

/**
 * Logout from Poweradmin
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<void>}
 */
export async function logout(page) {
  // Navigate directly to logout page for reliable logout
  await page.goto('/logout');
  await page.waitForURL(/login/);
}

/**
 * Check if user is logged in
 *
 * @param {import('@playwright/test').Page} page - Playwright page object
 * @returns {Promise<boolean>}
 */
export async function isLoggedIn(page) {
  // Check if we're not on the login page
  const currentUrl = page.url();
  return !currentUrl.includes('/login');
}
