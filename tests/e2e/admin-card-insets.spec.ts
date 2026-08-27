import { test, expect, type Page } from "./support/test";
import { TEST_ADMIN, login, seedUser } from "./support/config";

/**
 * Every admin card starts its header and its body at the same x, and every
 * card on a page starts them at the same x as every other card.
 *
 * THIS IS THE THIRD REPORT. `_partials/_card_chrome.html.twig` has defined the
 * card once for a while now — `band()` is `px-4 py-3.5`, `body()` is
 * `px-4 py-5` — but only nine admin templates ever called it, and the rest hand
 * wrote `px-5 py-4`, `px-5 py-2`, `px-5 py-6`, `px-5 py-8` and `px-6 py-6`. Four
 * pixels is small enough to survive review and large enough to see: on Reported
 * mail the body text sat right of the heading above it down the whole card, and
 * on Integrations the group label, the card edge, the card content and the
 * paragraph between the cards each began somewhere different.
 *
 * MEASURED, NOT SCREENSHOT. A screenshot test of ten admin sections would need
 * re-baselining every time a number in a panel changed — these pages are live
 * telemetry — and when it did fail it would say "the image differs" about a
 * page-sized image. What is asserted here is the number a human would get with
 * a ruler: `getBoundingClientRect().left` plus the computed `padding-left`, which
 * is where the content of a block actually begins whether the padding is on the
 * block, on its rows or on a table cell inside it. The failure names the section,
 * the card, and both x values, because "expected 27 to be 32" would not stop a
 * fourth report.
 *
 * Three separate things are checked, and they fail for different reasons:
 *
 *   A. within one card — the band and each body agree. A body that hand-rolled
 *      its own inset.
 *   B. across one page — every card insets its content from its own edge by the
 *      same amount. Two cards that each hand-rolled a DIFFERENT figure, which is
 *      how a page ends up with three.
 *   C. around the cards — a group label or an explanatory paragraph sitting
 *      between cards begins where the cards' content begins. This is the
 *      Integrations symptom, and no amount of fixing the cards themselves
 *      catches it.
 *
 * The static counterpart is tests/Infrastructure/Templating/
 * CardsUseTheChromeMacrosTest.php, which reads the markup and therefore sees
 * the branches this run does not render — an empty state, a pager, the tinted
 * card that only appears once something has been handled. It lost its `Admin`
 * prefix when the fourth report turned out to be about admin and settings
 * disagreeing with each other: it now sweeps both trees against the one house
 * figure, which is the only way that question has an answer.
 *
 * The settings half has a rendered twin of its own, deliberately built to the
 * same three checks and the same tolerances — tests/e2e/settings-card-insets
 * .spec.ts. Its header explains the two places it has to differ.
 *
 * Own admin user and own session, for the reason admin-panels.spec.ts gives:
 * /admin needs ROLE_ADMIN and granting it to the shared e2e user mid-run
 * deauthenticates every other spec.
 */
const ADMIN = TEST_ADMIN;

test.use({ storageState: { cookies: [], origins: [] } });

test.beforeAll(() => {
    seedUser({ email: ADMIN.email, password: ADMIN.password, admin: true });
});

/**
 * Every admin section, and the turbo-frame each one loads.
 *
 * Mirrors AdminDashboardController::SECTIONS. The frame ids are here rather
 * than a bare list of names so the wait is a real one — an unknown `section`
 * falls back to `system` server-side, so a section renamed out from under this
 * spec would otherwise quietly measure the system panels ten times and pass.
 * Push has two frames: the configuration and the delivery log are separate
 * because one holds a paste while the other re-renders on every filter change.
 */
const SECTIONS: Record<string, string[]> = {
    system: ["admin-live"],
    database: ["admin-db"],
    logs: ["admin-logs"],
    "insight-reports": ["admin-insight-reports"],
    integrations: ["admin-integrations"],
    push: ["admin-push", "admin-push-deliveries"],
    ai: ["admin-ai"],
    users: ["admin-users"],
    backup: ["admin-backup"],
    reset: ["admin-reset"],
};

/** How far apart two edges may be before it is a defect and not a rounding. */
const TOLERANCE = {
    /** Band against body, inside one card: both sit inside the same border. */
    withinCard: 1,
    /** Card against card, each measured from its own edge. */
    betweenCards: 1,
    /** A bare label against a card, which is one border-width further in. */
    aroundCards: 2,
};

/**
 * Waits until a section has finished arriving.
 *
 * Every frame first paints the same spinner placeholder, and the AI section
 * fetches its telemetry panel a second time from inside the frame — so "no
 * spinner left anywhere in here" is the one condition that covers both without
 * naming either.
 */
async function openSection(page: Page, section: string): Promise<void> {
    await page.goto(`/admin?section=${section}`);

    for (const frame of SECTIONS[section]) {
        await expect(page.locator(`#${frame}`)).toBeAttached();
        await expect(page.locator(`#${frame} .fa-circle-notch`)).toHaveCount(0);
    }
}

/** What one section's frames measured. */
type Measurement = {
    offences: string[];
    cards: number;
};

async function measure(page: Page, section: string, frames: string[]): Promise<Measurement> {
    return page.evaluate(
        ({ section, frames, tolerance }) => {
            const offences: string[] = [];
            let cards = 0;

            const round = (value: number) => Math.round(value * 10) / 10;

            const visible = (el: Element): boolean => {
                const box = el.getBoundingClientRect();

                return 0 < box.width && 0 < box.height;
            };

            /**
             * The x where a block's content begins.
             *
             * Its own left edge plus its padding — or, when it has none, the
             * first thing inside it that does. A card body is one of three
             * shapes here and all three have to answer the same question: a
             * padded box (chrome.body()), a bare wrapper around rows that pad
             * themselves, or a full-bleed table whose first cell carries the
             * inset. Descending until something is padded reads all three
             * without having to know which one this is.
             */
            const contentStart = (el: Element): number => {
                let node: Element | null = el;

                // Deep enough for a table (div → table → thead → tr → th) and
                // shallow enough that a nested widget cannot be mistaken for
                // the block's own inset.
                for (let depth = 0; null !== node && depth < 8; depth++) {
                    const padding = parseFloat(getComputedStyle(node).paddingLeft) || 0;

                    if (0 < padding) {
                        return node.getBoundingClientRect().left + padding;
                    }

                    node = Array.from(node.children).find(visible) ?? null;
                }

                return el.getBoundingClientRect().left;
            };

            /** What to call a card in a failure message. */
            const name = (card: Element): string => {
                const heading = card.querySelector("h2, h3");
                const text = heading?.textContent?.trim();

                return text && "" !== text ? text : (card.id || card.className.split(" ")[0]);
            };

            for (const frameId of frames) {
                const frame = document.getElementById(frameId);

                if (null === frame) {
                    offences.push(`${section}: the frame #${frameId} never arrived`);
                    continue;
                }

                // A card is `chrome.shell()` — and the two tinted cards that
                // repeat its figures because the macro pins `border-line` and
                // they are not that colour. `overflow-hidden` is what separates
                // a card from the row-shaped panels beside it (a user row, a
                // mail registration), which are `rounded-pane` too and are
                // deliberately not cards.
                const panels = Array.from(frame.querySelectorAll(".rounded-pane")).filter(visible);
                const cardList = panels.filter((panel) => panel.classList.contains("overflow-hidden"));

                cards += cardList.length;

                const insets: { card: string; inset: number }[] = [];

                for (const card of cardList) {
                    const children = Array.from(card.children).filter(visible);
                    const [band, ...bodies] = children;

                    if (undefined === band) {
                        continue;
                    }

                    const bandStart = contentStart(band);

                    insets.push({ card: name(card), inset: round(bandStart - card.getBoundingClientRect().left) });

                    // A. The card against itself.
                    for (const body of bodies) {
                        const bodyStart = contentStart(body);
                        const drift = Math.abs(bodyStart - bandStart);

                        if (drift > tolerance.withinCard) {
                            offences.push(
                                `${section} › "${name(card)}": header content starts at x=${round(bandStart)}, ` +
                                    `body content at x=${round(bodyStart)} — ${round(drift)}px apart. ` +
                                    `The band and the body must both come from _partials/_card_chrome.html.twig.`,
                            );
                        }
                    }
                }

                // B. The cards against each other. Measured from each card's own
                // edge, because a card in a two-column grid legitimately sits at
                // a different absolute x from the one beside it.
                const distinct = [...new Set(insets.map((entry) => entry.inset))];

                if (1 < distinct.length) {
                    const spread = Math.max(...distinct) - Math.min(...distinct);

                    if (spread > tolerance.betweenCards) {
                        offences.push(
                            `${section}: the cards on this page inset their content by ${distinct.join("px, ")}px — ` +
                                `they must all agree. ` +
                                insets.map((entry) => `"${entry.card}" ${entry.inset}px`).join("; "),
                        );
                    }
                }

                // C. What sits between the cards. A group label or a paragraph
                // outside every panel, with an inset of its own — it has to
                // begin where the panels' content begins, or the page reads as
                // several columns rather than one.
                const flush = panels.filter(
                    (panel) => 1 >= Math.abs(panel.getBoundingClientRect().left - frame.getBoundingClientRect().left),
                );

                if (0 === flush.length) {
                    continue;
                }

                const reference = contentStart(flush[0]);

                for (const node of Array.from(frame.querySelectorAll("*")).filter(visible)) {
                    if (null !== node.closest(".rounded-pane")) {
                        continue;
                    }

                    const padding = parseFloat(getComputedStyle(node).paddingLeft) || 0;

                    if (0 === padding || "" === (node.textContent ?? "").trim()) {
                        continue;
                    }

                    const start = node.getBoundingClientRect().left + padding;
                    const drift = Math.abs(start - reference);

                    if (drift > tolerance.aroundCards) {
                        offences.push(
                            `${section}: "${(node.textContent ?? "").trim().slice(0, 40)}" starts at x=${round(start)} ` +
                                `but the cards' content starts at x=${round(reference)} — ${round(drift)}px apart. ` +
                                `Labels and prose between cards take the same px-4 the cards do.`,
                        );
                    }

                    // One report per block: its children would each repeat it.
                    break;
                }
            }

            return { offences, cards };
        },
        { section, frames, tolerance: TOLERANCE },
    );
}

test("every admin section lines its cards up, inside and against each other", async ({ page }) => {
    // Ten sections, each a navigation and a frame fetch.
    test.setTimeout(120_000);

    await login(page, ADMIN.email, ADMIN.password);

    const offences: string[] = [];
    let cards = 0;

    // Every section in one test, and every offence collected before anything is
    // asserted. Ten tests would be ten logins, which trips the throttle on a
    // re-run; and stopping at the first bad card would report one of them and
    // hide the other nine, which is exactly how this came to be reported three
    // times.
    for (const [section, frames] of Object.entries(SECTIONS)) {
        await openSection(page, section);

        const measured = await measure(page, section, frames);

        offences.push(...measured.offences);
        cards += measured.cards;
    }

    // The selector still finds cards. Without this a `.rounded-pane` renamed in
    // the stylesheet would empty the sweep and leave a test that passes by
    // measuring nothing.
    expect(cards, "the sweep found no admin cards at all — has the card markup changed?").toBeGreaterThan(15);

    expect(offences.join("\n"), "admin cards disagree about where their content starts").toBe("");
});
