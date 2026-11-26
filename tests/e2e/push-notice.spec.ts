import type { BrowserContext, CDPSession, Page, Worker } from "@playwright/test";
import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, TEST_USER, bulkMore, consoleCommand, mailRow, seed } from "./support/config";

/**
 * What a browser's "new mail" notification says (#760), driven through the real
 * thing: a push delivered to /sw.js over the DevTools protocol, the worker's own
 * request for /settings/push/inbox-state on this user's session, the server's
 * answer for the mail this user has, and the notification the worker shows,
 * read back from its registration.
 *
 * On the full Chromium in its new headless mode: the headless shell the other
 * specs run on refuses notification permission whatever the context grants.
 *
 * The push arrives with no plMail window open, as it would for somebody
 * looking at another tab: with one focused the worker stays quiet, the page
 * updates itself.
 */

test.use({ channel: "chromium", permissions: ["notifications"] });

type Shown = { title: string; body: string; url: string; tag: string };

const STATE_CHANGE = JSON.stringify({ "@type": "StateChange", changed: { "1": { Email: "99" } } });

/** Registers /sw.js for this context and returns what delivers a push to it. */
async function serviceWorker(page: Page, context: BrowserContext): Promise<{ worker: Worker; push: () => Promise<void> }> {
    await page.goto("/settings/notifications");
    await page.evaluate(async () => {
        await navigator.serviceWorker.register("/sw.js");
        await navigator.serviceWorker.ready;
    });

    const cdp: CDPSession = await context.newCDPSession(page);
    const registrations: { registrationId: string; scopeURL: string }[] = [];
    cdp.on("ServiceWorker.workerRegistrationUpdated", (event) => registrations.push(...event.registrations));
    await cdp.send("ServiceWorker.enable");
    await expect.poll(() => registrations.length).toBeGreaterThan(0);

    const origin = new URL(page.url()).origin;
    const registrationId = registrations[0].registrationId;
    const worker = context.serviceWorkers()[0] ?? (await context.waitForEvent("serviceworker"));

    // No plMail window from here on.
    await page.goto("about:blank");

    return {
        worker,
        push: () => cdp.send("ServiceWorker.deliverPushMessage", { origin, registrationId, data: STATE_CHANGE }).then(() => undefined),
    };
}

/** The notification the worker is showing for the push, once it shows one. */
async function shown(worker: Worker, timeout = 10_000): Promise<Shown> {
    const read = () => worker.evaluate(async () => {
        const registration = (self as unknown as { registration: ServiceWorkerRegistration }).registration;

        return (await registration.getNotifications()).map((n) => ({ title: n.title, body: n.body, url: n.data?.url ?? "", tag: n.tag }));
    });

    await expect.poll(async () => (await read()).length, { timeout }).toBe(1);

    return (await read())[0];
}

/** A switch in Settings → Notifications; the radios are sr-only and the form posts itself. */
async function setSwitch(page: Page, name: "showContent" | "skipRead", value: "1" | "0"): Promise<void> {
    await page.goto("/settings/notifications");

    // Already so: a click on the checked radio changes nothing and posts nothing.
    if (await page.locator(`input[name="${name}"][value="${value}"]`).isChecked()) {
        return;
    }

    const posted = page.waitForResponse(
        (response) => response.request().method() === "POST" && response.url().endsWith("/settings/notifications"),
    );

    await page.locator(`label:has(input[name="${name}"][value="${value}"])`).click();
    await posted;
    await page.waitForLoadState("load");
    await expect(page.locator(`input[name="${name}"][value="${value}"]`)).toBeChecked();
}

/**
 * Changes this worker's copy of a seeded message behind the app's back, as
 * another device or an earlier delivery would have. Through the console, so
 * the quotes have to survive a template literal, a shell and psql in turn.
 */
function alterMessage(subject: string, set: string): void {
    consoleCommand(
        `dbal:run-sql "UPDATE message SET ${set} FROM account WHERE message.account_id = account.id ` +
            `AND account.usr_id = (SELECT id FROM \\"user\\" WHERE email = '${TEST_USER.email}') AND message.subject = '${subject}'"`,
    );
}

/** Tick conversations by their subjects and mark them read from the toolbar. */
async function markRead(page: Page, ...subjects: string[]): Promise<void> {
    await page.goto("/mail/inbox");

    for (const subject of subjects) {
        await mailRow(page, subject).locator("label:has(input[data-thread-select])").click();
    }

    await bulkMore(page, "Mark as read");
    await expect(mailRow(page, subjects[0])).toHaveAttribute("data-unread", "false", { timeout: 5000 });
}

test.beforeEach(() => {
    seed("seed-mail");
});

test("off by default: the notification says only \"New mail\"", async ({ page, context }) => {
    const { worker, push } = await serviceWorker(page, context);

    await push();

    expect(await shown(worker)).toEqual({ title: "plMail", body: "New mail", url: "/mail/inbox", tag: "plmail-new-mail" });
});

/**
 * A click focuses a plMail window already on the page the notification opens,
 * or under it, and opens one otherwise. The match ends where a path segment
 * does: a notice for conversation 12 must not focus the window on 123.
 */
test("a click finds the window on that page, not one whose address merely starts the same", async ({ page, context }) => {
    const { worker } = await serviceWorker(page, context);
    const cases: [string, string, boolean][] = [
        ["/mail/inbox", "/mail/inbox", true],
        ["/mail/inbox?page=2", "/mail/inbox", true],
        ["/mail/inbox/3", "/mail/inbox", true],
        ["/mail/thread/12", "/mail/thread/12", true],
        ["/mail/thread/12?bin=1", "/mail/thread/12", true],
        ["/mail/thread/123", "/mail/thread/12", false],
        ["/mail/inboxes", "/mail/inbox", false],
        ["/calendar/day/2026-11-26", "/calendar/day/2026-11-26", true],
    ];

    const found = await worker.evaluate(
        (pairs) => pairs.map(([url, target]) =>
            (globalThis as unknown as { isAt: (u: string, t: string) => boolean }).isAt(self.location.origin + url, target)),
        cases.map(([url, target]) => [url, target]),
    );

    expect(found).toEqual(cases.map(([, , focused]) => focused));
});

/**
 * And which window: as Gmail does, a click never opens a second tab while a
 * plMail window is open. One on the page is brought forward as it is; else the
 * one used last (first in matchAll's order) is brought forward and taken
 * there; a window is opened only when none is.
 *
 * The windows are stand-ins with an address: headless Chromium cannot click a
 * notification, so the choice is checked and the focus() it leads to is not.
 */
test("a click takes the open plMail window to the page rather than opening another", async ({ page, context }) => {
    const { worker } = await serviceWorker(page, context);
    const cases: [string[], string, string | null][] = [
        [[], "/mail/thread/12", null],
        [["/mail/inbox"], "/mail/thread/12", "/mail/inbox → navigate"],
        [["/calendar", "/mail/thread/12"], "/mail/thread/12", "/mail/thread/12 as it is"],
        [["/mail/thread/123"], "/mail/thread/12", "/mail/thread/123 → navigate"],
        [["/settings", "/mail/inbox"], "/mail/inbox", "/mail/inbox as it is"],
        [["/settings", "/calendar"], "/mail/inbox", "/settings → navigate"],
    ];

    const chosen = await worker.evaluate(
        (rows) => rows.map(([paths, target]) => {
            const windows = (paths as string[]).map((path) => ({ url: self.location.origin + path, focus: () => undefined }));
            const choice = (globalThis as unknown as {
                windowFor: (w: unknown[], t: string) => { client: { url: string }; navigate: boolean } | null;
            }).windowFor(windows, target as string);

            return null === choice
                ? null
                : `${new URL(choice.client.url).pathname}${choice.navigate ? " → navigate" : " as it is"}`;
        }),
        cases.map(([paths, target]) => [paths, target]),
    );

    expect(chosen).toEqual(cases.map(([, , expected]) => expected));
});

test.describe("with \"Show sender and subject\" on", () => {
    test.beforeEach(async ({ page }) => {
        await setSwitch(page, "showContent", "1");
    });

    // The switches are the user's, not the test's: put them back for the next spec.
    test.afterEach(async ({ page }) => {
        await setSwitch(page, "showContent", "0");
        await setSwitch(page, "skipRead", "0");
    });

    test("one message: its sender and subject, and a click opens it", async ({ page, context }) => {
        await markRead(page, INBOX_SUBJECTS.read, INBOX_SUBJECTS.star, INBOX_SUBJECTS.archive);
        const { worker, push } = await serviceWorker(page, context);

        await push();
        const notice = await shown(worker);

        expect(notice.title).toBe("E2E Sender");
        expect(notice.body).toBe(INBOX_SUBJECTS.trash);
        expect(notice.url).toMatch(/^\/mail\/thread\/\d+$/);
        expect(notice.tag).toBe("plmail-new-mail");
    });

    test("several that came together: how many, and who from", async ({ page, context }) => {
        const { worker, push } = await serviceWorker(page, context);

        await push();

        expect(await shown(worker)).toEqual({ title: "4 new messages", body: "E2E Sender", url: "/mail/inbox", tag: "plmail-new-mail" });
    });

    test("no answer from plMail, signed out here: \"New mail\" all the same", async ({ page, context }) => {
        const { worker, push } = await serviceWorker(page, context);
        const cookies = await context.cookies();

        await context.clearCookies();
        await push();
        const notice = await shown(worker);
        await context.addCookies(cookies);

        expect(notice).toEqual({ title: "plMail", body: "New mail", url: "/mail/inbox", tag: "plmail-new-mail" });
    });

    /**
     * With "Only notify for unread mail" on the worker waits and asks again;
     * the words come from that second answer. Here the mail changes while it
     * waits: one unread message when the push arrives, four when it asks again.
     */
    test("with the unread check on, the words come from the second answer", async ({ page, context }) => {
        test.setTimeout(90_000);

        await setSwitch(page, "skipRead", "1");
        await markRead(page, INBOX_SUBJECTS.read, INBOX_SUBJECTS.star, INBOX_SUBJECTS.archive);
        const { worker, push } = await serviceWorker(page, context);

        await push();
        consoleCommand(`app:test:seed-mail --email=${TEST_USER.email}`);

        expect(await shown(worker, 40_000)).toEqual({ title: "4 new messages", body: "E2E Sender", url: "/mail/inbox", tag: "plmail-new-mail" });
    });

    /**
     * The second answer is about the mail of the first and anything newer:
     * the message the push was for, read on the phone during the wait, is not
     * stood in for by an older unread letter. No notification at all then,
     * and certainly not one naming that older letter as if it had just come.
     */
    test("with the unread check on, mail read during the wait is not stood in for by older mail", async ({ page, context }) => {
        test.setTimeout(90_000);

        await setSwitch(page, "skipRead", "1");
        await markRead(page, INBOX_SUBJECTS.read, INBOX_SUBJECTS.star);
        alterMessage(INBOX_SUBJECTS.archive, "created_at = message.created_at - INTERVAL '5 minutes'");
        const { worker, push } = await serviceWorker(page, context);

        const asked = (again: boolean) => context.waitForEvent("response", {
            predicate: (response) => response.url().includes("/settings/push/inbox-state") && again === response.url().includes("since="),
            timeout: 40_000,
        });
        const first = asked(false);
        const second = asked(true);

        await push();
        await first;
        alterMessage(INBOX_SUBJECTS.trash, "seen_at = NOW()");
        await second;

        // showNothing() runs right after the answer; give it the moment it takes.
        await page.waitForTimeout(1000);
        expect(await worker.evaluate(async () => {
            const registration = (self as unknown as { registration: ServiceWorkerRegistration }).registration;

            return (await registration.getNotifications()).map((n) => n.body);
        })).toEqual([]);
    });
});
