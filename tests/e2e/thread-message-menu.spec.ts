import { test, expect } from "./support/test";
import { mailRow, seed } from "./support/config";
import { settled } from "./support/motion";

/**
 * A message's overflow menu is painted above the message BELOW it.
 *
 * REMINDER: run `php bin/console asset-map:compile` after a CSS change, or this
 * reads the previous build. Proving this fix took two runs for exactly that
 * reason — `tailwind:build` alone is not enough.
 *
 * The menu has been z-30 since it was written and that never helped, because
 * z-index is only meaningful among siblings of one stacking context. The menu
 * lives inside a message header, `sticky` makes that header a context of its
 * own, and the NEXT message's header is also sticky, also z-10, and later in
 * the document — so it outranks the entire context the menu sits in. The menu
 * was drawn underneath the following message every single time, which reads as
 * the popover being clipped rather than as one header covering another. It was
 * reported twice.
 *
 * Asserted on the computed z-index of the two headers rather than on a
 * screenshot: the bug is a number, the fix is a number, and a screenshot
 * baseline would need re-approving every time the thread view is restyled.
 */
test("a message's menu outranks the message below it", async ({ page }) => {
    seed("seed-conversation");

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto("/mail/inbox");
    await settled(page);

    await mailRow(page, "E2E Conversation").click();

    const heads = page.locator(".thread-message-head");
    await expect(heads.first()).toBeVisible({ timeout: 10_000 });
    expect(await heads.count()).toBeGreaterThan(1);

    // At rest every header agrees, which is what makes document order decide.
    const atRest = await page.evaluate(
        () => [...document.querySelectorAll(".thread-message-head")]
            .map((h) => getComputedStyle(h).zIndex),
    );
    expect(atRest[0]).toBe(atRest[1]);

    await page.locator('[data-action*="mail--message-actions#toggle"]').first().click();
    await expect(page.locator('[data-mail--message-actions-target="panel"]:not(.hidden)').first())
        .toBeVisible();

    // The header holding the OPEN panel, and the one after it — found through
    // the panel rather than by index, so the test still means something if the
    // toolbar grows another menu of its own.
    const z = await page.evaluate(() => {
        const panel = document.querySelector('[data-mail--message-actions-target="panel"]:not(.hidden)');
        const own   = panel?.closest(".thread-message-head") ?? null;
        const heads = [...document.querySelectorAll(".thread-message-head")];
        const next  = heads[heads.indexOf(own as Element) + 1] ?? null;

        return {
            own:  null === own ? null : Number(getComputedStyle(own).zIndex),
            next: null === next ? null : Number(getComputedStyle(next).zIndex),
        };
    });

    expect(z.own, "the open menu's header did not resolve").not.toBeNull();
    expect(z.next, "there has to be a message below it for this to mean anything").not.toBeNull();
    expect(z.own!, "the header holding an open menu must outrank the one below it")
        .toBeGreaterThan(z.next!);
});

/**
 * The menu closes when the message itself is pressed (#807).
 *
 * The message body is a sandboxed frame, a document of its own, so a press
 * in it never reached the page's click listener and the menu stayed open over
 * the mail — on a phone, where the message is nearly all there is to tap,
 * there was no way to close it but the ⋮ button again. The frame now reports
 * the press (outside_press.js). Escape closes it too.
 */
test("a message's menu closes on a press inside the message, and on Escape", async ({ page }) => {
    // An HTML mail, so the body is the sandboxed frame.
    seed("seed-mail", "seed-rendering");

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto("/mail/inbox");
    await settled(page);

    await mailRow(page, "E2E Long Body").click();

    const frame = page.locator('iframe[data-mail--message-frame-target="frame"]').first();
    await expect(frame).toBeVisible({ timeout: 10_000 });

    const toggle = page.locator('[data-action*="mail--message-actions#toggle"]').first();
    const open   = page.locator('[data-mail--message-actions-target="panel"]:not(.hidden)');

    await toggle.click();
    await expect(open).toHaveCount(1);

    await page.frameLocator('iframe[data-mail--message-frame-target="frame"]').first()
        .locator("body").click({ position: { x: 5, y: 5 } });
    await expect(open, "a press inside the message left the menu open").toHaveCount(0);

    await toggle.click();
    await expect(open).toHaveCount(1);

    await page.keyboard.press("Escape");
    await expect(open, "Escape left the menu open").toHaveCount(0);
});
