import { test, expect } from "./support/test";
import type { Page } from "@playwright/test";
import { mailRow, seed } from "./support/config";

/**
 * "Show quoted text", checked in a real browser — because the whole feature is
 * a claim about what the sandboxed frame DOES, and none of it can be proved on
 * markup alone.
 *
 * The body renders inside an opaque-origin iframe the parent cannot reach into,
 * so the collapse is server-rendered (QuoteCollapser) and the toggle runs in
 * the frame's own nonce'd script. What matters to a reader is exactly the two
 * things asserted here: the sender's new text shows and the quoted history does
 * not, until the toggle is pressed — at which point the quote appears and the
 * frame grows to fit it (reusing the height-reporting the frame-height spec
 * covers).
 *
 * Kept in sync with App\Command\Test\SeedRenderingCommand.
 */
const SUBJECT = "E2E Quoted Reply";
const NEW_TEXT = "This is my brand-new reply text.";
const QUOTED_TEXT = "This is the original quoted history that starts hidden.";
const MIN_HEIGHT = 80;

test.beforeEach(() => {
    seed("seed-mail", "seed-rendering");
});

test.describe("collapse quoted text", () => {
    const iframe = (page: Page) => page.locator(".mail-message-body iframe").first();

    async function frameHeight(page: Page): Promise<number> {
        return (await iframe(page).boundingBox())?.height ?? 0;
    }

    test("keeps the reply history hidden until the toggle reveals it", async ({ page }) => {
        await page.goto("/mail/inbox");
        await mailRow(page, SUBJECT).click();

        await expect(iframe(page)).toBeVisible();
        const frame = page.frameLocator(".mail-message-body iframe").first();

        // The sender's own words are on screen; the quoted history is in the DOM
        // but hidden behind the toggle.
        await expect(frame.getByText(NEW_TEXT)).toBeVisible();
        await expect(frame.getByText(QUOTED_TEXT)).toBeHidden();

        // A real, accessibly-named button — its name comes from aria-label,
        // since the visible affordance is only "···".
        const toggle = frame.getByRole("button", { name: "Show quoted text" });
        await expect(toggle).toBeVisible();
        await expect(toggle).toHaveAttribute("aria-expanded", "false");

        // Let the collapsed frame settle before measuring, so the growth check
        // is a fact and not a race.
        await expect
            .poll(async () => frameHeight(page), { timeout: 5000 })
            .toBeGreaterThanOrEqual(MIN_HEIGHT);
        const collapsedHeight = await frameHeight(page);

        await toggle.click();

        // The quote is now visible, the button flips its state and name, and the
        // frame has grown to make room for what was just shown.
        await expect(frame.getByText(QUOTED_TEXT)).toBeVisible();
        await expect(frame.getByRole("button", { name: "Hide quoted text" }))
            .toHaveAttribute("aria-expanded", "true");

        await expect
            .poll(async () => frameHeight(page), { timeout: 5000 })
            .toBeGreaterThan(collapsedHeight);
    });
});
