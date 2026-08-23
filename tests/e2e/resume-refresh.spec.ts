import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * Coming back to the app shows what happened while you were away.
 *
 * Reported from Android: read status and the "New" marker do not update as they
 * should. The cause is not the marker — it is that nothing refreshes on resume.
 *
 * A phone suspends a backgrounded browser. The Mercure stream is dropped, the
 * poll timer stops, and server-sent events have no replay, so every change made
 * while the app was away is missed. The pane only refreshed when a sync event
 * had arrived WHILE hidden — and on a suspended phone there are none to arrive.
 * So the list came back showing the state from before: mail read on another
 * device still bold, badges another client had already retired still on screen.
 *
 * On the desktop the minute-long poll hid it, which is why it reads as an
 * Android bug rather than a general one.
 *
 * The change here is one line — refresh whenever the page becomes visible — and
 * this is what keeps it: the state is changed OUT OF BAND, with no event the
 * page could have received, which is exactly the situation being fixed.
 */
test.beforeEach(() => {
    seed("seed-mail");
});

test("a mail read elsewhere shows as read when you come back to the app", async ({ page }) => {
    await page.goto("/mail/inbox");

    const row = mailRow(page, INBOX_SUBJECTS.read);
    await expect(row).toBeVisible();
    await expect(row).toHaveAttribute("data-unread", "true");

    const threadId = await row.getAttribute("id");
    const token = await page.locator('meta[name="csrf-token"]').getAttribute("content");

    // Away. Nothing this page can hear happens from here on.
    await hide(page);

    // Read somewhere else. Through the request context rather than the page:
    // it shares the session, so it is the same user on another device — and
    // crucially the page itself is never told, which is the whole situation
    // being fixed.
    const response = await page.request.post(
        `/status/thread/${threadId?.replace("thread_", "")}/read`,
        {
            headers: { "Content-Type": "application/json", "X-CSRF-Token": token ?? "" },
            data: { read: true },
        },
    );

    expect(response.status(), "the out-of-band read was refused").toBe(200);

    // Still stale, because nothing has told this page anything.
    await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveAttribute("data-unread", "true");

    await show(page);

    // Back — and the list is re-read. Before this change the page sat on its
    // stale markup until the next poll: up to a minute on a desktop, and on a
    // suspended phone until something else happened to wake it.
    await expect(
        mailRow(page, INBOX_SUBJECTS.read),
        "returning to the app left the list showing the state from before",
    ).toHaveAttribute("data-unread", "false", { timeout: 15_000 });
});

/**
 * `document.hidden` is read-only, so backgrounding has to be simulated by
 * redefining it and firing the event the app listens for. That is exactly what
 * a phone does to a suspended browser, minus the suspension itself — which is
 * not something a test can ask for and not what is under test either.
 */
async function hide(page: import("@playwright/test").Page): Promise<void> {
    await page.evaluate(() => {
        Object.defineProperty(document, "hidden", { value: true, configurable: true });
        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
        document.dispatchEvent(new Event("visibilitychange"));
    });
}

async function show(page: import("@playwright/test").Page): Promise<void> {
    await page.evaluate(() => {
        Object.defineProperty(document, "hidden", { value: false, configurable: true });
        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
        document.dispatchEvent(new Event("visibilitychange"));
    });
}
