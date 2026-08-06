import { expect } from "@playwright/test";
import { test } from "./support/test";
import { INBOX_SUBJECTS, TEST_USER, consoleCommand, seed } from "./support/config";

/**
 * "Happening Soon" — what is coming up, opened from the topbar.
 *
 * A browser spec rather than more render tests because the two things that can
 * go wrong here are only true in a browser. The trigger is conditional markup
 * on every authenticated page, so "it appears when there is something" cannot be
 * asserted anywhere else; and the provenance link lives inside a <turbo-frame>,
 * where Turbo's default is to load a click into the frame it came from — the
 * failure is a whole mailbox rendered inside a dialog, which every server-side
 * test in this repo would call a 200.
 *
 * **Everything here is asserted about the seeded booking's own row**, never
 * about the panel being otherwise empty or about which row is first. The panel
 * lists what the owner typed as well as what was read out of mail, so anything
 * else on this user's calendar — a fixture another spec file left behind, an
 * event a person made while looking at the app — is legitimately in it too. A
 * spec that assumed an empty calendar was asserting the absence of other specs.
 * The empty state itself is pinned where it can be reached honestly, in
 * HappeningSoonRenderTest.
 *
 * Seeds and clears its own fixture. The seeded booking would otherwise leave a
 * topbar button on this worker's user for whichever spec file runs next, and
 * screenshots.spec.ts would photograph it.
 */

const TITLE = "E2E flight to Berlin";

/** What the trigger is worded as, from messages.en.yaml. */
const OPEN_LABEL = "What is happening soon";

function clearFixture(): void {
    consoleCommand(`app:test:seed-extracted-event --email=${TEST_USER.email} --clear`);
}

test.describe("happening soon", () => {
    // seed-mail first and in the same call: the fixture links its booking to a
    // message that command creates, and seed-mail deletes every thread on the
    // account it seeds — so a reseed after this one would take the provenance
    // link with it and leave the row with nothing to point at.
    test.beforeAll(() => {
        seed("seed-mail", "seed-extracted-event");
    });

    test.afterAll(() => {
        clearFixture();
    });

    test("the topbar offers the panel when something is coming up", async ({ page }) => {
        await page.goto("/mail/inbox");

        await expect(page.getByRole("button", { name: OPEN_LABEL })).toBeVisible();
    });

    /**
     * The icon is the kind's, off ExtractionKind::icon() — a plane says both
     * "there is a reason to look" and what the reason is.
     *
     * Read off the booking's own row rather than off the topbar trigger, which
     * wears whichever thing is soonest and is therefore a claim about the rest
     * of the calendar rather than about this booking.
     */
    test("the booking's row wears its kind", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("button", { name: OPEN_LABEL }).click();

        const row = page.locator("#modal-backdrop li").filter({ hasText: TITLE });

        await expect(row).toBeVisible();
        await expect(row.locator("i.fa-plane")).toBeVisible();
    });

    test("the panel names the booking and the mail it was read out of", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("button", { name: OPEN_LABEL }).click();

        const modal = page.locator("#modal-backdrop");

        // Waits on the row's own text, not on the backdrop. The modal keeps
        // whatever the previous dialog put in it until Turbo swaps the frame,
        // so a wait on visibility can pass against the last dialog's markup.
        await expect(modal.getByText(TITLE)).toBeVisible();
        await expect(
            modal.getByRole("link", { name: `From ${INBOX_SUBJECTS.read}` }),
        ).toBeVisible();
    });

    /**
     * Regression guard: the provenance link must leave the dialog. Without
     * data-turbo-frame="_top" Turbo treats it as a navigation of #modal, and
     * the mailbox loads inside the dialog with no way back to the mail.
     */
    test("the provenance link opens the message rather than loading it into the dialog", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("button", { name: OPEN_LABEL }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal.getByText(TITLE)).toBeVisible();

        await modal.getByRole("link", { name: `From ${INBOX_SUBJECTS.read}` }).click();

        await expect(page).toHaveURL(/\/mail\/message\/\d+/);
        await expect(page.locator("#modal-backdrop")).toBeHidden();
    });

    /**
     * Last, because it takes the fixture away.
     *
     * Asserts the booking is gone rather than that the panel is empty: the panel
     * lists the owner's own events too, so "empty" is a claim about every other
     * spec file that has run against this user. What must be true is that
     * dropping the row drops its line — see the note at the top of this file,
     * and HappeningSoonRenderTest for the empty state itself.
     */
    test("a booking that is gone stops being listed", async ({ page }) => {
        clearFixture();

        await page.goto("/calendar/soon");

        await expect(page.getByText(TITLE)).toHaveCount(0);
        await expect(
            page.getByRole("link", { name: `From ${INBOX_SUBJECTS.read}` }),
        ).toHaveCount(0);
    });
});
