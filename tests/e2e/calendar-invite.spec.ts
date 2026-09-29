import { expect, type Page } from "@playwright/test";
import { test } from "./support/test";
import { mailRow, seed } from "./support/config";

/**
 * Answering an invitation shows the meeting in the calendar at once.
 *
 * Until calendar--invite existed, Yes redrew the card and put a toast in the
 * far corner, and the calendar pane beside it — the one place the answer
 * changed anything — went on showing the week without the meeting until a
 * reload. So what these assert is the CALENDAR, and that the page was not
 * reloaded to get there: a marker set on `window` beforehand survives only if
 * nothing navigated.
 *
 * One answer each at the two motion levels that behave differently. At Full,
 * Yes flies to its slot; under reduced motion nothing travels, and hovering the
 * answer outlines the slot instead — Maybe there, because a Maybe is striped,
 * in the outline and in the calendar both. The flyer lives for a little over
 * half a second, so it is recorded by an observer set up before the click
 * rather than looked for after it.
 *
 * `app:test:seed-invite` hangs an unanswered invitation, today at 14:30, on the
 * seeded "E2E Read Me" message, and rewrites it on every run — so each test
 * answers a fresh one.
 */

/** SeedInviteCommand::TITLE and ::SOURCE_SUBJECT. */
const TITLE = "E2E Intro call";
const SOURCE = "E2E Read Me";

test.describe("answering an invitation", () => {
    test.beforeEach(async ({ page }) => {
        seed("seed-invite");

        // The pane open beside the mail, on the week — the view a slot is
        // worked out in. Visiting a view is how the pane remembers it.
        await page.request.get("/calendar/week?pane=1");
        await paneMode(page, "split");
    });

    test.afterEach(async ({ page }) => {
        // The pane mode is stored on the worker's user, which every other spec
        // on this worker shares; see calendar-pane.spec.ts.
        await paneMode(page, "mail");
        seed("seed-invite --clear");
    });

    test("Yes flies to the calendar, and the meeting is drawn without a reload", async ({ page }) => {
        await openInvitation(page);

        const chip = meetingInPane(page);

        await expect(chip).toHaveCount(0);
        await watchForFlights(page);

        await card(page).getByRole("button", { name: "Yes" }).click();

        await expect(chip).toBeVisible();
        await expect(card(page).getByText("Accepted")).toBeVisible();
        expect(await page.evaluate(() => (window as unknown as Watch).__flew)).toBe(true);
        expect(await page.evaluate(() => (window as unknown as Watch).__stayed)).toBe(true);

        // Nothing is left behind once the real block is in: no flyer, and no
        // placeholder standing in for it.
        await expect(page.locator(".invite-flyer")).toHaveCount(0);
        await expect(page.locator("#invite-slot")).toHaveCount(0);
    });

    test("under reduced motion, Maybe outlines a striped slot, nothing flies, and it is drawn striped", async ({ page }) => {
        await page.emulateMedia({ reducedMotion: "reduce" });
        await openInvitation(page);
        await watchForFlights(page);

        const maybe = card(page).getByRole("button", { name: "Maybe" });

        await maybe.hover();
        await expect(page.locator('#calendar-pane-frame [data-invite-slot="preview"] > [data-tentative]')).toBeVisible();

        await maybe.click();

        await expect(meetingInPane(page)).toBeVisible();
        await expect(meetingInPane(page)).toHaveAttribute("data-tentative");
        expect(await page.evaluate(() => (window as unknown as Watch).__flew)).toBe(false);
        expect(await page.evaluate(() => (window as unknown as Watch).__stayed)).toBe(true);
    });
});

interface Watch {
    __flew: boolean;
    __stayed: boolean;
}

function card(page: Page) {
    return page.locator("[data-controller~='calendar--invite']");
}

function meetingInPane(page: Page) {
    return page.locator("#calendar-pane-frame [data-event-chip]").filter({ hasText: TITLE });
}

async function openInvitation(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await mailRow(page, SOURCE).click();
    await expect(card(page)).toBeVisible();
    await expect(page.locator("#calendar-pane-frame [data-grid-zone]")).toBeVisible();
}

/** Note any flyer the page adds, and set the marker a reload would wipe. */
async function watchForFlights(page: Page): Promise<void> {
    await page.evaluate(() => {
        const watch = window as unknown as Watch;

        watch.__flew = false;
        watch.__stayed = true;

        new MutationObserver((records) => {
            for (const record of records) {
                for (const node of record.addedNodes) {
                    if (node instanceof Element && node.classList.contains("invite-flyer")) {
                        watch.__flew = true;
                    }
                }
            }
        }).observe(document.body, { childList: true });
    });
}

/** Put the calendar pane in a mode through the endpoint the switch posts to. */
async function paneMode(page: Page, mode: "mail" | "split"): Promise<void> {
    await page.goto("/mail/inbox");
    await page.evaluate(async (wanted) => {
        const shell = document.querySelector("[data-controller~='ui--split']");

        if (null === shell) {
            return;
        }

        const body = new FormData();

        body.append("_token", shell.getAttribute("data-ui--split-token-value") ?? "");
        body.append("mode", wanted);
        await fetch(shell.getAttribute("data-ui--split-state-url-value") ?? "", {
            method: "POST",
            body,
            headers: { "X-Requested-With": "fetch" },
        });
    }, mode);
}
