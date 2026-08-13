import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * Addressing a message.
 *
 * The recipient fields are the part of compose that silently loses data: a
 * chip that never committed is an address the message will not go to, and the
 * form submits the underlying <select>, not what is on screen. So every
 * assertion here reads the committed chips rather than the text in the box.
 *
 * Suggestions come from the contact book, which the suite seeds none of —
 * contacts are learned from mail. They are made the way the application makes
 * them, by addressing a draft, which is also the round trip worth having.
 */

const DOCK = "#compose_dock";
const KNOWN = "recipients-known@example.test";
const OTHER = "recipients-other@example.test";

// The To field's own suggestion panel. A bare `.ts-dropdown` matches five
// elements in this window — the typeface and size pickers are Tom Selects too.
const TO_PANEL = "#compose_toAddresses-ts-dropdown";

test.beforeAll(() => {
    seed("seed-mail", "clear-drafts");
});

async function openCompose(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();
    await expect(page.locator(`${DOCK} .ts-control`).first()).toBeVisible();
}

/** The "To" field's committed chips. */
function chips(page: Page) {
    return page.locator(`${DOCK} .ts-control`).first().locator(".item");
}

async function addRecipient(page: Page, address: string): Promise<void> {
    const input = page.locator(`${DOCK} .ts-control input`).first();

    await input.fill(address);
    await input.press("Enter");
}

test.describe("compose recipients", () => {
    test("a typed address becomes a chip, and several stay several", async ({ page }) => {
        await openCompose(page);

        await addRecipient(page, KNOWN);
        await addRecipient(page, OTHER);

        await expect(chips(page)).toHaveCount(2);
        await expect(chips(page).first()).toContainText(KNOWN);
    });

    /**
     * Removing one must remove that one. Tom Select renders chips and the
     * <select> separately, so a remove that only takes the chip away leaves
     * the address on the message.
     */
    test("removing a chip removes the address, not just the chip", async ({ page }) => {
        await openCompose(page);

        await addRecipient(page, KNOWN);
        await addRecipient(page, OTHER);

        await chips(page).first().locator(".remove").click();

        await expect(chips(page)).toHaveCount(1);
        await expect(chips(page).first()).toContainText(OTHER);

        // Read as values, not as text: Tom Select mints these options itself
        // and gives them no label, so their text content is empty while the
        // value is the address the form would submit.
        // By id, not position: the first <select> in the dock is the account
        // to send from, and Tom Select leaves the real recipient field hidden
        // beside the chips it renders.
        // Typed as HTMLSelectElement: `evaluate` infers SVGElement | HTMLElement
        // from a bare locator, and neither has selectedOptions — so this line
        // was the one thing in the repo that failed `npm run typecheck`.
        const selected = await page
            .locator(`${DOCK} select[id$="toAddresses"]`)
            .evaluate((node: HTMLSelectElement) =>
                Array.from(node.selectedOptions).map((option) => option.value),
            );

        expect(selected).toContain(OTHER);
        expect(selected).not.toContain(KNOWN);
    });

    /** Cc and Bcc are their own fields, and must not collect the To address. */
    test("Cc and Bcc address separately", async ({ page }) => {
        await openCompose(page);

        await addRecipient(page, KNOWN);

        await page.locator(DOCK).getByRole("button", { name: /^Cc$/ }).click();

        const cc = page.locator(`${DOCK} .ts-control`).nth(1);
        await cc.locator("input").fill(OTHER);
        await cc.locator("input").press("Enter");

        await expect(chips(page)).toHaveCount(1);
        await expect(cc.locator(".item")).toHaveCount(1);
        await expect(cc.locator(".item")).toContainText(OTHER);
    });

    /**
     * Autocomplete over the contact book — which is the point of it, and the
     * reason a draft is saved first: contacts are learned, not seeded.
     */
    test("a known correspondent is suggested and committed by Tab", async ({ page }) => {
        await openCompose(page);
        await addRecipient(page, KNOWN);

        const saved = page.waitForResponse(
            (r) => r.url().includes("/compose/draft") && r.request().method() === "POST",
        );
        await page.locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`).fill("Learning a contact");
        await saved;

        await openCompose(page);

        const input = page.locator(`${DOCK} .ts-control input`).first();
        await input.fill("recipients-known");

        await expect(page.locator(`${TO_PANEL} .active`)).toContainText(KNOWN);
        await input.press("Tab");

        await expect(chips(page)).toContainText(KNOWN);
    });

    /**
     * Enter in the recipient field commits a chip. It must not submit the
     * form — pressing it while addressing would send a message with no body,
     * and there is no taking that back beyond the undo window.
     */
    test("Enter commits a recipient rather than sending", async ({ page }) => {
        await openCompose(page);

        await addRecipient(page, KNOWN);

        await expect(chips(page)).toHaveCount(1);
        await expect(page.locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`)).toBeVisible();
    });
});

/**
 * What the To field does when it is unhappy, and what it does once it is happy
 * again.
 *
 * All of these came out of a minute of manual use, and none were things the
 * tests above could see: they assert what is COMMITTED, and these are about
 * what is SHOWN — a message that outlives its cause, a message that names the
 * wrong cause, text thrown away without being mentioned, and a suggestion panel
 * that reopens over the rest of the form.
 */
test.describe("the To field's feedback", () => {
    const ERRORS = `${DOCK} [data-compose--compose-target="errors"]`;

    function toInput(page: Page) {
        return page.locator(`${DOCK} .ts-control input`).first();
    }

    /**
     * The complaint ends when the thing complained about is fixed.
     *
     * It used to stay indefinitely — cleared only by the next Send attempt —
     * holding the Subject row about 19px lower than it belongs the whole time.
     */
    test("the no-recipient message clears as soon as a recipient is added", async ({ page }) => {
        await openCompose(page);

        await page.locator(DOCK).getByRole("button", { name: "Send", exact: true }).click();

        const errors = page.locator(ERRORS);
        await expect(errors).toBeVisible();
        await expect(errors).toContainText(/at least one recipient/i);

        // The row it displaces is the point of the complaint, so measure the
        // displacement itself rather than the Subject row's absolute position —
        // committing a chip grows the To row by about as much as the message
        // shrinks it, and the two cancel out to no movement at all.
        const pushed = (await errors.boundingBox())!.height;
        expect(pushed, "the message takes up room while it is shown").toBeGreaterThan(0);

        await addRecipient(page, KNOWN);

        await expect(errors).toBeHidden();
        await expect(chips(page)).toHaveCount(1);
        expect(await errors.boundingBox(), "and gives it all back").toBeNull();
    });

    /**
     * Send with a malformed address in the box: say what is actually wrong, and
     * KEEP THE TEXT.
     *
     * It used to report "add at least one recipient" — true of the committed
     * chips, useless to someone looking at an address they had just typed — and
     * Tom Select then dropped the uncommittable string on blur, so the thing
     * that needed fixing vanished while being complained about.
     */
    test("Send on an invalid address says so, and does not throw the text away", async ({
        page,
    }) => {
        await openCompose(page);

        await toInput(page).fill("keine-gueltige-adresse");
        await page.locator(DOCK).getByRole("button", { name: "Send", exact: true }).click();

        const errors = page.locator(ERRORS);

        await expect(errors).toBeVisible();
        await expect(errors).toContainText(/not a valid email address/i);
        await expect(errors).not.toContainText(/at least one recipient/i);

        // Still there to be corrected.
        await expect(toInput(page)).toHaveValue("keine-gueltige-adresse");
        await expect(chips(page)).toHaveCount(0);
    });

    /**
     * A valid address typed but never confirmed is still an address the user
     * entered. Send commits it rather than reporting an empty field.
     */
    test("Send commits an address that was typed but not confirmed", async ({ page }) => {
        await openCompose(page);

        await toInput(page).fill(OTHER);
        await page
            .locator(`${DOCK} [data-compose--compose-target="subject"]`)
            .fill("Uncommitted recipient");
        await page
            .locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`)
            .fill("Body text long enough to matter.");

        await page.locator(DOCK).getByRole("button", { name: "Send", exact: true }).click();

        await expect(page.locator(ERRORS)).toBeHidden();
        await expect(page.locator(`${DOCK} .compose-window`)).toHaveCount(0, { timeout: 10_000 });
    });

    /**
     * The suggestion panel does not reopen over the rest of the form.
     *
     * After Enter it used to spring back — covering the Subject row and the top
     * of the body — and to return on every subsequent focus, so Subject was
     * repeatedly unreachable with a mouse.
     */
    test("the suggestion panel stays shut after a chip is committed", async ({ page }) => {
        await openCompose(page);

        const dropdown = page.locator(`${DOCK} .ts-dropdown`).first();

        await addRecipient(page, KNOWN);
        await expect(chips(page)).toHaveCount(1);
        await expect(dropdown).toBeHidden();

        // And it stays shut when focus comes back — what made Subject
        // unreachable was the reopening, not the first opening.
        await toInput(page).click();
        await expect(dropdown).toBeHidden();

        // Measured rather than inferred: the Subject row can actually be
        // clicked, which is the complaint in its original form.
        const subject = page.locator(`${DOCK} [data-compose--compose-target="subject"]`);

        await subject.click();
        await expect(subject).toBeFocused();
    });

    /**
     * The refusal is tied to the field it is about.
     *
     * It was announced (`role="alert"`) and otherwise orphaned: the combobox
     * reported `aria-invalid` null and `aria-describedby` null, so anyone
     * arriving at the input afterwards had no way to know it was the thing
     * being complained about. The calendar form does this properly; compose was
     * the outlier.
     */
    test("the refusal is wired to the input, and unwired again when it clears", async ({
        page,
    }) => {
        await openCompose(page);

        const input = toInput(page);
        const errors = page.locator(ERRORS);

        await page.locator(DOCK).getByRole("button", { name: "Send", exact: true }).click();
        await expect(errors).toBeVisible();

        await expect(input).toHaveAttribute("aria-invalid", "true");

        const describedBy = await input.getAttribute("aria-describedby");
        expect(describedBy, "the input points at the message").toBeTruthy();
        expect(await errors.getAttribute("id")).toBe(describedBy);

        await addRecipient(page, KNOWN);

        await expect(errors).toBeHidden();
        await expect(input).not.toHaveAttribute("aria-invalid", "true");
    });
});

/**
 * Clicking out of the To field with the mouse.
 *
 * Driven with real mouse clicks at real coordinates, deliberately: the keyboard
 * path (Enter, then Tab) is what every spec above uses, and it was never broken.
 * The reported fault exists only for a pointer, because it is about what is
 * PAINTED where the user aims.
 *
 * The bug: `.ts-dropdown` is absolutely positioned, so the suggestion panel took
 * up no room and hung over the rows below it. Measured on the dock window, the
 * Subject input sat at y=346..366 and the open panel at y=331..412 — covering it
 * completely, label and all, so `document.elementFromPoint` at the centre of the
 * Subject field answered `.option` / `.create` rather than the input.
 *
 * Both reported symptoms are that one fact:
 *
 *   • the click lands on the "Add <typed>" row, which commits the address and
 *     leaves focus in the control — so the subject typed next went into the
 *     recipient box, and a second click was needed to get out of it;
 *   • or it lands on a highlighted SUGGESTION, which enrols a contact nobody
 *     chose onto a message the user believes they are still addressing. That is
 *     the mis-addressing risk, and it is why these click by coordinate rather
 *     than by locator: a locator click REFUSES when something covers the
 *     target, which is exactly the condition under test, so it would have
 *     reported this bug as a timeout instead of as the wrong recipient.
 */
test.describe("clicking out of the To field", () => {
    const SUBJECT = `${DOCK} input[id$="subject"]`;

    function toInput(page: Page) {
        return page.locator(`${DOCK} .ts-control input`).first();
    }

    /** The tag name of whatever is actually on top at the Subject input's centre. */
    async function whatCoversSubject(page: Page): Promise<string | null> {
        return page.evaluate(() => {
            const subject = document.querySelector('#compose_dock input[id$="subject"]');

            if (subject === null) {
                return null;
            }

            const box = subject.getBoundingClientRect();
            const top = document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2);

            return top === null ? null : top.tagName;
        });
    }

    /**
     * The root cause, asserted on its own: whatever else the panel does, it may
     * not be over the next field. Separate from the behavioural tests below
     * because it fails with the actual fault ("a DIV is on top of the Subject
     * input") rather than with a downstream symptom.
     */
    test("the suggestion panel never covers the Subject field", async ({ page }) => {
        await openCompose(page);

        const input = toInput(page);
        await input.click();
        await input.pressSequentially(OTHER, { delay: 20 });

        await expect(page.locator(TO_PANEL)).toBeVisible();

        expect(
            await whatCoversSubject(page),
            "the Subject input must be the topmost element at its own centre",
        ).toBe("INPUT");
    });

    /**
     * 1a. One click, one focus change, and the text goes where it was aimed.
     * The tester's `document.activeElement` read `compose_toAddresses-ts-control`
     * after this click, and the subject they then typed appeared as recipient text.
     */
    test("one click on Subject moves focus there, and what is typed next lands in it", async ({
        page,
    }) => {
        await openCompose(page);

        const input = toInput(page);
        await input.click();
        await input.pressSequentially(OTHER, { delay: 20 });
        await expect(page.locator(TO_PANEL)).toBeVisible();

        await page.locator(SUBJECT).click();

        await expect(page.locator(SUBJECT)).toBeFocused();

        await page.keyboard.type("Quarterly numbers");

        await expect(page.locator(SUBJECT)).toHaveValue("Quarterly numbers");
    });

    /**
     * 1b. The mis-addressing one: a suggestion that is merely HIGHLIGHTED is not
     * a suggestion that was CHOSEN, and clicking elsewhere must not enrol it. In
     * the report this silently added `Paul Lützner <mail@pluetzner.de>` to a
     * message addressed to somebody else.
     *
     * Asserted on the <select> rather than on the chips: the chips are Tom
     * Select's rendering, and the <select> is what the form actually submits.
     */
    test("a highlighted suggestion is not committed by clicking away", async ({ page }) => {
        // Contacts are learned, not seeded — address a draft to make one.
        await openCompose(page);
        await addRecipient(page, KNOWN);

        const saved = page.waitForResponse(
            (r) => r.url().includes("/compose/draft") && r.request().method() === "POST",
        );
        await page
            .locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`)
            .fill("Learning a contact");
        await saved;

        await openCompose(page);

        const input = toInput(page);
        await input.click();
        await input.pressSequentially("recipients-known", { delay: 20 });

        // The condition under test: a suggestion is highlighted, and nothing has
        // been clicked or Tab-ed to accept it.
        await expect(page.locator(`${TO_PANEL} .active`)).toContainText(KNOWN);

        await page.locator(SUBJECT).click();
        await expect(page.locator(SUBJECT)).toBeFocused();

        const selected = await page
            .locator(`${DOCK} select[id$="toAddresses"]`)
            .evaluate((node: HTMLSelectElement) =>
                Array.from(node.selectedOptions).map((option) => option.value),
            );

        expect(selected, "clicking Subject must not enrol the highlighted contact").toHaveLength(0);
    });

    /**
     * The same onto the message body, which the panel also overhung — in the
     * report that one added `Amazon.de <shipment-tracking@amazon.de>`.
     */
    test("a highlighted suggestion is not committed by clicking into the body", async ({
        page,
    }) => {
        await openCompose(page);

        const input = toInput(page);
        await input.click();
        await input.pressSequentially("recipients-known", { delay: 20 });
        await expect(page.locator(`${TO_PANEL} .active`)).toContainText(KNOWN);

        await page.locator(`${DOCK} [data-compose--compose-toolbar-target="editor"]`).click();

        const selected = await page
            .locator(`${DOCK} select[id$="toAddresses"]`)
            .evaluate((node: HTMLSelectElement) =>
                Array.from(node.selectedOptions).map((option) => option.value),
            );

        expect(selected, "clicking the body must not enrol the highlighted contact").toHaveLength(0);
    });
});
