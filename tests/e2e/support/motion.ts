import { expect, type Page } from "@playwright/test";

/**
 * Wait until nothing on the page is moving.
 *
 * plMail animates a whole list arriving: the rows come in from the left over
 * 600ms, staggered — see MotionLevel::listBase(). For that time the list is
 * genuinely somewhere else than where it will end up, which is fine for a
 * person and fatal for a test that measures or clicks the moment a page loads.
 * Two ways it shows up, both of which cost real debugging before this existed:
 *
 *   - a forced click lands at the element's CURRENT box, which for a row still
 *     sliding in is off the left edge of the list, so the click hits whatever
 *     is behind it and the checkbox never toggles;
 *   - a bounding box read for a "does this fit on a phone" assertion reads a
 *     position partway through the travel, and reports a negative x for
 *     something that fits perfectly well once it has arrived.
 *
 * So: anything that asserts about POSITION, or clicks through a coordinate,
 * waits for this first. Anything asserting about content does not need it.
 *
 * `getAnimations()` covers CSS animations and transitions both, and returns
 * only what is running right now — including the room-making transform on a
 * refresh, which is the other thing in plMail that moves rows about.
 */
export async function settled(page: Page): Promise<void> {
    await expect
        .poll(() => page.evaluate(() => document.getAnimations().length), { timeout: 5000 })
        .toBe(0);
}
