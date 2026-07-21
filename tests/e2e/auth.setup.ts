import { test as setup } from "@playwright/test";
import { login } from "./support/config";

const STORAGE_STATE = "playwright/.auth/user.json";

/**
 * Signs in through the real UI and saves the authenticated cookies so the
 * main project can reuse the session without re-logging in per test.
 */
setup("authenticate", async ({ page }) => {
    await login(page);
    await page.context().storageState({ path: STORAGE_STATE });
});
