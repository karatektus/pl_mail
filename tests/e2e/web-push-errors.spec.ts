import { test, expect, type Page } from "./support/test";

/**
 * A failed "Enable notifications" says which step failed and why (#277).
 *
 * The report it answers: a Pixel 8, Chrome and Brave, permission allowed, and
 * then at once "Something went wrong. Try again." Every failure in the enable
 * path landed in that one sentence and the exception was dropped, so neither
 * the reporter nor anyone reading the report could tell a proxy mangling
 * /sw.js from a push service refusing the device from the server answering
 * 403.
 *
 * The browser's push machinery is stubbed rather than driven. A real
 * subscription needs a push service on the other end and VAPID keys the test
 * stack does not have, and neither is what is being tested: the question is
 * only what the pane says when one of the three steps throws or is refused.
 * The status endpoint is answered "configured" for the same reason, or the
 * button stays disabled on a server without keys.
 */

type Failure = "worker" | "subscribe" | "none";

async function stubPush(page: Page, failure: Failure): Promise<void> {
    await page.addInitScript((failAt: Failure) => {
        const subscription = {
            toJSON: () => ({
                endpoint: "https://push.example.test/device",
                keys: { p256dh: "p256dh", auth: "auth" },
            }),
        };

        const registration = {
            pushManager: {
                getSubscription: async () => null,
                subscribe: async () => {
                    if ("subscribe" === failAt) {
                        throw new DOMException("Registration failed - push service error", "AbortError");
                    }

                    return subscription;
                },
            },
        };

        Object.defineProperty(navigator, "serviceWorker", {
            configurable: true,
            value: {
                register: async () => {
                    if ("worker" === failAt) {
                        throw new DOMException("The script has an unsupported MIME type", "SecurityError");
                    }

                    return registration;
                },
                ready: Promise.resolve(registration),
                getRegistration: async () => registration,
            },
        });

        Notification.requestPermission = async () => "granted";
    }, failure);

    await page.route("**/settings/push/status/**", (route) =>
        route.fulfill({ json: { configured: true, registered: false, verified: false } }),
    );
}

async function enable(page: Page) {
    await page.goto("/settings?section=notifications");

    const toggle = page.getByRole("button", { name: "Enable notifications" });
    await expect(toggle).toBeEnabled();
    await toggle.click();

    return page.locator('[data-settings--web-push-target="state"]');
}

test.describe("enabling notifications fails with a reason", () => {
    test("a service worker that will not register is named, with its error", async ({ page }) => {
        await stubPush(page, "worker");

        const state = await enable(page);

        await expect(state).toHaveText(/background worker did not start \(SecurityError\)/);
        await expect(state).toHaveAttribute("data-push-state", "error");
    });

    test("a push service that refuses the device is named, with Brave's switch", async ({ page }) => {
        await stubPush(page, "subscribe");

        const state = await enable(page);

        await expect(state).toHaveText(/push service gave this device no address \(AbortError\)/);
        await expect(state).toHaveText(/Use Google services for push messaging/);
    });

    test("a server that refuses the subscription is named, with its status", async ({ page }) => {
        await stubPush(page, "none");
        await page.route("**/settings/push/subscribe", (route) =>
            route.fulfill({ status: 403, json: { error: "Invalid CSRF token." } }),
        );

        const state = await enable(page);

        await expect(state).toHaveText(/server turned this device down \(HTTP 403\)/);
    });
});
