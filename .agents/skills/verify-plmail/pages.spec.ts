import { writeFileSync } from "node:fs";
import { expect, noSidewaysScroll, notAnErrorPage, settle, shot, test } from "./support";

const paths = (process.env.VERIFY_PATHS ?? "").split("\n").filter(Boolean);

for (const path of paths) {
  test(`land on ${path}`, async ({ page }) => {
    const response = await page.goto(path);
    expect(response?.status(), `${path} answers`).toBeLessThan(400);
    await expect(page, "still signed in as the demo user").not.toHaveURL(/\/login/);
    await notAnErrorPage(page);
    await settle(page, 800);
    await shot(page, "viewport");
    await shot(page, "full-page", { fullPage: true });
    writeFileSync(test.info().outputPath("aria-snapshot.yml"), await page.locator("body").ariaSnapshot());
    await noSidewaysScroll(page);
  });
}
