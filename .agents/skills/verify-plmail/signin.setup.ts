import { test } from "@playwright/test";
import { login } from "../../../tests/e2e/support/config";
import { AUTH_STATE, DEMO } from "./support";

test("sign in as the demo user", async ({ page }) => {
  await login(page, DEMO.email, DEMO.password);
  await page.context().storageState({ path: AUTH_STATE });
});
