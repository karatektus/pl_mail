import { expect, test as base, type Page, type TestInfo } from "@playwright/test";
import { tmpdir } from "node:os";
import { join } from "node:path";

export { expect };

export const DEMO = {
  email: process.env.VERIFY_DEMO_EMAIL ?? "demo@plmail.test",
  password: process.env.VERIFY_DEMO_PASSWORD ?? "demo-password-not-secret",
};

export const AUTH_STATE = join(process.env.TMPDIR ?? tmpdir(), "plmail-verify-demo.json");

const shots = new WeakMap<TestInfo, number>();

/**
 * Fails the test on an uncaught page exception, a console error or a 5xx from plMail,
 * so a screenshot of a broken page never passes as evidence.
 */
export const test = base.extend<{ guards: void }>({
  guards: [
    async ({ page, baseURL }, use, testInfo) => {
      const problems: string[] = [];
      page.on("pageerror", (error) => problems.push(`page error: ${error.message}`));
      page.on("console", (message) => {
        if (message.type() === "error") problems.push(`console error: ${message.text()}`);
      });
      page.on("response", (response) => {
        if (response.status() >= 500 && baseURL && response.url().startsWith(baseURL)) {
          problems.push(`${response.status()} from ${response.url()}`);
        }
      });
      await use();
      if (problems.length > 0) {
        await testInfo.attach("problems", { body: problems.join("\n"), contentType: "text/plain" });
      }
      expect(problems, "the page stayed free of errors").toEqual([]);
    },
    { auto: true },
  ],
});

/** Fails when the page is a Symfony error page rather than the page asked for. */
export async function notAnErrorPage(page: Page): Promise<void> {
  await expect(page.locator('[class^="exception"], [class*=" exception"], .trace')).toHaveCount(0);
  await expect(page).not.toHaveTitle(/exception|forbidden|error/i);
}

/** Lets transitions and Turbo frames finish before a shot. */
export async function settle(page: Page, ms = 500): Promise<void> {
  await page.waitForLoadState("networkidle").catch(() => undefined);
  await page.waitForTimeout(ms);
}

/** Saves a numbered viewport screenshot (or the full page) into the test's evidence folder. */
export async function shot(page: Page, name: string, options: { fullPage?: boolean } = {}): Promise<void> {
  const info = test.info();
  const n = (shots.get(info) ?? 0) + 1;
  shots.set(info, n);
  const file = `${String(n).padStart(2, "0")}-${name.toLowerCase().replace(/[^a-z0-9]+/g, "-")}.png`;
  await page.screenshot({ path: info.outputPath(file), fullPage: options.fullPage ?? false });
}

/** Fails when the page scrolls sideways at this width. */
export async function noSidewaysScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );
  expect(overflow, "the page does not scroll sideways").toBeLessThanOrEqual(1);
}
