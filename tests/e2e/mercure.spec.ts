import { test, expect, type Page } from "./support/test";
import { createHmac } from "node:crypto";
import { execSync } from "node:child_process";

/**
 * Live updates over Mercure: that they arrive at all, that the subscription is
 * authorized rather than anonymous, and that the stream comes back on its own
 * after the hub has been unreachable.
 *
 * That last one is the reason this file exists. EventSource retries by itself
 * only when a connection drops cleanly; an HTTP error response is fatal per
 * spec, so a hub restart — or a reverse proxy answering 502 for a few seconds
 * during a deploy — used to end live updates in every open tab until someone
 * pressed reload. Nothing surfaced it, because the mail UI has no polling
 * fallback and the page carried on looking live.
 *
 * Needs a hub. `npm run test:e2e:docker` brings one up (compose.test.yaml);
 * the specs skip themselves rather than fail when there is none, so a run
 * against a bare `symfony serve` stays green instead of reporting a fault in
 * the app.
 */

const HUB_PATH = "/.well-known/mercure";
const TOPIC_FOR = (userId: string) => `mail/user/${userId}`;

/** Same fixed secret compose.test.yaml gives both the app and the hub. */
const JWT_SECRET = process.env.MERCURE_JWT_SECRET ?? "e2e-mercure-secret-not-for-production";

const b64url = (input: string) => Buffer.from(input).toString("base64url");

/**
 * A publisher token. The suite publishes directly to the hub rather than
 * triggering a real sync: this is a test of the delivery path, and driving it
 * through IMAP would make it a slow test of something else.
 */
function publisherJwt(): string {
    const header = b64url(JSON.stringify({ alg: "HS256", typ: "JWT" }));
    const payload = b64url(JSON.stringify({ mercure: { publish: ["*"] } }));
    const signature = createHmac("sha256", JWT_SECRET)
        .update(`${header}.${payload}`)
        .digest("base64url");

    return `${header}.${payload}.${signature}`;
}

async function publish(baseURL: string, topic: string, data: unknown): Promise<void> {
    const body = new URLSearchParams();
    body.append("topic", topic);
    body.append("data", JSON.stringify(data));

    const response = await fetch(`${baseURL}${HUB_PATH}`, {
        method: "POST",
        headers: {
            Authorization: `Bearer ${publisherJwt()}`,
            "Content-Type": "application/x-www-form-urlencoded",
        },
        body,
    });

    expect(response.status, "publishing to the hub").toBe(200);
}

/**
 * Stops or starts the hub container.
 *
 * The compose file is named explicitly because this must never touch the dev
 * stack's hub, which is a different project and may well be serving the
 * developer's own mail while the suite runs.
 */
function hub(action: "stop" | "start"): void {
    execSync(`docker compose -f compose.test.yaml ${action} mercure`, { stdio: "ignore" });
}

/** Blocks until the hub answers again, so recovery is timed from a known point. */
async function waitForHub(baseURL: string): Promise<void> {
    for (let attempt = 0; attempt < 30; attempt++) {
        if (await hubPresent(baseURL)) {
            return;
        }

        await new Promise((resolve) => setTimeout(resolve, 1000));
    }

    throw new Error("hub did not come back up");
}

/** Is a hub actually reachable? Used to skip rather than fail without one. */
async function hubPresent(baseURL: string): Promise<boolean> {
    try {
        // No credentials: a hub that is up answers 401 to this, which is itself
        // the proof it is both present and not anonymous.
        const response = await fetch(`${baseURL}${HUB_PATH}?topic=probe`, {
            signal: AbortSignal.timeout(4000),
        });

        return response.status === 401 || response.status === 200;
    } catch {
        return false;
    }
}

/**
 * Records what the page's stream is doing, from the page's own point of view.
 *
 * Reads the state events the mercure controller dispatches rather than poking
 * at EventSource directly, so the assertions are about what the app believes —
 * which is what the topbar indicator renders and what the user acts on.
 */
async function observe(page: Page): Promise<void> {
    await page.evaluate(() => {
        const w = window as unknown as { __mercure?: { states: string[]; updates: unknown[] } };
        if (w.__mercure) {
            return;
        }

        w.__mercure = { states: [], updates: [] };
        document.addEventListener("core--mercure:state", (event) => {
            w.__mercure!.states.push((event as CustomEvent).detail.state);
        });
        document.addEventListener("core--mercure:mailbox-synced", (event) => {
            w.__mercure!.updates.push((event as CustomEvent).detail);
        });
    });
}

const updates = (page: Page) =>
    page.evaluate(() => (window as never as { __mercure: { updates: unknown[] } }).__mercure.updates);

/** The status dot beside the wordmark. */
const indicator = (page: Page) => page.locator(".stream-dot");

/**
 * Waits for a connection state.
 *
 * Reads the indicator's `data-state` rather than the event stream, because the
 * events are a one-shot broadcast: a listener installed after the page has
 * already connected sees nothing and waits forever for something that has
 * happened. The attribute is the state, not a notification of it, so it is
 * correct whenever it is read.
 */
function expectState(page: Page, state: string, timeout = 15000) {
    return expect(indicator(page)).toHaveAttribute("data-state", state, { timeout });
}

/** The signed-in user's id, which is what the topic is keyed by. */
async function currentUserId(page: Page): Promise<string> {
    const url = await page.getAttribute("body", "data-core--mercure-url-value");
    const topic = new URL(url!, "http://localhost").searchParams.get("topic");

    return topic!.replace("mail/user/", "");
}

test.describe("mercure live updates", () => {
    test.beforeEach(async ({ baseURL }) => {
        test.skip(!(await hubPresent(baseURL!)), "no Mercure hub reachable");
    });

    test("delivers an update to an open page without a reload", async ({ page, baseURL }) => {
        await page.goto("/mail/inbox");
        await observe(page);

        // The connection has to be established before publishing: Mercure only
        // replays past updates for a subscriber presenting a Last-Event-ID, and
        // this one is connecting for the first time.
        await expectState(page, "connected");

        const userId = await currentUserId(page);
        await publish(baseURL!, TOPIC_FOR(userId), {
            type: "mailbox.synced",
            mailboxId: 1,
            accountId: 1,
            specialUse: "inbox",
        });

        await expect
            .poll(async () => (await updates(page)).length, {
                message: "published update never reached the page",
                timeout: 10000,
            })
            .toBeGreaterThan(0);
    });

    /**
     * The regression this whole change exists for.
     *
     * The hub is genuinely stopped rather than faked. Two cheaper approaches
     * were tried first and both proved to test nothing:
     *
     *   - `context.setOffline(true)` does NOT sever an SSE connection that is
     *     already established. The page stayed "connected" throughout and no
     *     reconnect was ever attempted.
     *   - Consequently `page.route(...502)` never fired either — routing does
     *     intercept EventSource, but only when a request is actually made.
     *
     * Stopping the container is what reproduces the real thing: the upstream
     * disappears, our own Caddy answers 502, and EventSource treats that as
     * fatal. Docker-only, which is why the describe block skips without a hub.
     */
    test("recovers on its own after the hub has been unreachable", async ({ page, baseURL }) => {
        test.slow();

        await page.goto("/mail/inbox");
        await observe(page);

        await expectState(page, "connected");

        hub("stop");

        try {
            await expectState(page, "offline", 30000);
        } finally {
            // Even on failure: leaving the hub down would cascade into every
            // spec that runs after this one.
            hub("start");
        }

        await waitForHub(baseURL!);

        // The assertion the whole change exists for: nobody reloaded, nobody
        // clicked anything, and the stream is back.
        await expectState(page, "connected", 60000);

        // Reconnected is not the same as working: prove a fresh update actually
        // arrives on the new connection.
        const userId = await currentUserId(page);
        const before = (await updates(page)).length;
        await publish(baseURL!, TOPIC_FOR(userId), {
            type: "mailbox.synced",
            mailboxId: 1,
            accountId: 1,
            specialUse: "inbox",
        });

        await expect
            .poll(async () => (await updates(page)).length, {
                message: "reconnected, but updates are not flowing",
                timeout: 10000,
            })
            .toBeGreaterThan(before);
    });

    /**
     * The indicator is the whole reason a user ever learns the stream died —
     * there is nothing else on screen that changes when it does.
     */
    test("shows the stream's health on the topbar dot, and updates it when it drops", async ({ page, baseURL }) => {
        test.slow();

        await page.goto("/mail/inbox");

        // On screen without opening anything — this is the only surface that
        // reports the fault, so it has to be there before someone goes looking.
        const dot = indicator(page);
        await expect(dot).toBeVisible();

        await expectState(page, "connected");

        // A coloured dot means nothing on its own; the tooltip is what makes it
        // readable, so it is part of the feature rather than a nicety.
        await expect(dot).toHaveAttribute("title", /live updates/i);

        hub("stop");

        try {
            await expectState(page, "offline", 30000);
            await expect(dot).toHaveAttribute("title", /unavailable/i);
        } finally {
            hub("start");
        }

        await waitForHub(baseURL!);
        await expectState(page, "connected", 60000);
    });

    /**
     * The hub used to run dev.Caddyfile in every environment, including the
     * TrueNAS one, and dev.Caddyfile sets `anonymous`. Since the app proxies
     * /.well-known/mercure* publicly, anyone who could reach an instance could
     * subscribe to mail/user/<any id> and watch that account's sync activity.
     */
    test("refuses a subscription that presents no authorization", async ({ baseURL }) => {
        const response = await fetch(`${baseURL}${HUB_PATH}?topic=${encodeURIComponent("mail/user/1")}`, {
            signal: AbortSignal.timeout(5000),
        });

        expect(response.status, "an unauthorized subscriber must be refused").toBe(401);
    });
});
