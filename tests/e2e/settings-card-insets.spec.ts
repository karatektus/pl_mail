import { test, expect, type Page } from "./support/test";
import { TEST_USER, consoleCommand } from "./support/config";

/**
 * Every settings card starts its header and its body at the same x, every card
 * on a page starts them at the same x as every other card, and the headings
 * between the cards start there too.
 *
 * THIS IS THE FOURTH REPORT, and the first one about the two halves of the
 * application disagreeing with each other rather than about one page being
 * internally wrong: "STILL alot of padding issues between admin and user
 * settings cards."
 *
 * The admin half was swept and guarded first, and this file is deliberately its
 * twin — same three checks, same measurement, same tolerances — because a guard
 * that measured settings by a different rule is precisely how the two sides
 * would drift apart again. Read tests/e2e/admin-card-insets.spec.ts alongside
 * it; what follows is only what is DIFFERENT here.
 *
 * WHAT WAS ACTUALLY WRONG. Less than on the admin side, and hidden better.
 * settings/_card.html.twig already draws from _partials/_card_chrome.html.twig,
 * so every section that goes through that embed was correct all along — which is
 * why "settings is fine" was a reasonable thing to believe. The health section
 * does not go through it: it is a FEED of per-issue cards rather than one card,
 * so it hand-rolled its own chrome and drifted in three places at once. Its
 * heading cancelled its inset above the `@2xl` container breakpoint and began
 * 20px left of the first word beneath it; its healthy-state pane used px-5; and
 * its issue bodies used `px-4 @2xl:px-5`, so they were right on a narrow pane and
 * 4px out on every desktop one. Every other card in the application insets its
 * content 16px from its padding box; health managed 16, 20 and 20 in three
 * different places on one screen.
 *
 * FOUR THINGS THIS FILE DOES THAT THE ADMIN ONE DOES NOT:
 *
 *   1. Sections are NAVIGATIONS, not turbo-frames. /settings?section=x is a full
 *      page load and the nav is a set of ordinary links, so there is no frame id
 *      to wait on. What replaces it is the check that the section actually
 *      ARRIVED — see `openSection`. An unknown `section` falls back to `profile`
 *      server-side exactly as an unknown admin one falls back to `system`, so
 *      without that check a section renamed out from under this spec would
 *      measure the profile page eighteen times and pass.
 *
 *   2. A CARD IS `chrome.shell()` AND NOTHING ELSE, spelled here as
 *      `.rounded-pane.overflow-hidden.bg-surface`. The admin spec can say
 *      `.rounded-pane.overflow-hidden` because nothing else on those pages is
 *      shaped like that. Settings is full of things that are: every segmented
 *      control (`segGroup` in _ai, _insights and _appearance) is a
 *      `rounded-pane border border-line overflow-hidden` grid, and there are
 *      thirty of them on the Appearance page alone. None carries `bg-surface`,
 *      which the macro does, so that one extra class separates a card from a
 *      two-position switch. Without it this spec measures switches and reports
 *      that a "card" insets its content by 1px.
 *
 *   3. Check C matters MORE here and has to look wider. On admin it catches a
 *      padded group label; the offence in settings was a heading with NO padding
 *      at all — `@2xl:px-0` — which a scan of padded nodes skips by construction.
 *      So C measures where headings and prose outside every pane actually START,
 *      whether padding put them there or not. That direction is also the one the
 *      static counterpart cannot see: a threshold on "wider than px-4" has no
 *      lower twin, because px-0 through px-4 are all legitimate inside a card.
 *      See tests/Infrastructure/Templating/CardsUseTheChromeMacrosTest.php,
 *      which reads the markup and therefore covers the branches no run renders.
 *      C also measures its reference from the card's PADDING box rather than its
 *      border box, which admin never had to do — health's severity accent is a
 *      4px stripe, and comparing prose against the inside of it would demand a
 *      px-5 heading that the static guard rejects. See `paddingInset`.
 *
 *   4. It SEEDS THE STATE IT NEEDS. Everything above was true of a first version
 *      of this spec that still missed a defect, because health renders an
 *      all-clear pane on a healthy installation and the cards worth measuring
 *      only exist when something is broken. Run alone it measured the empty
 *      variant and passed; run in the full suite, where other specs break
 *      accounts as they go, it failed on a 4px drift it had never had the chance
 *      to see. A guard whose coverage depends on what the rest of the suite
 *      happens to have done is not a guard, so this one breaks an account of its
 *      own and puts it back. See `breakTheAccount`.
 *
 * This replaces a narrower spec that checked one section (General) for text
 * flush against a border. That assertion is subsumed by check A: a body with no
 * inset at all reads here as a header and body 16px apart, named and measured.
 *
 * No login of its own, unlike the admin twin: settings is ROLE_USER, so the
 * worker's own signed-in session from support/test.ts is exactly right.
 */

/**
 * Every settings section.
 *
 * Mirrors SettingsController::SECTIONS, in its order. Health leads there and
 * leads here for the same reason it is worth measuring at all — it is the one
 * section that is not built from settings/_card.html.twig.
 */
const SECTIONS = [
    "health",
    "accounts",
    "profile",
    "security",
    "labels",
    "calendars",
    "sharing",
    "filters",
    "insights",
    "ai",
    "integrations",
    "appearance",
    "aliases",
    "read-receipts",
    "signature",
    "app-passwords",
    "notifications",
    "general",
] as const;

/**
 * Sections confirmed by something they must have RENDERED, not by the nav.
 *
 * Health is the only one, and it earns the exception twice over.
 *
 * It has no nav entry to read: the Health link appears only when something is
 * actually wrong — see the note on the entry in settings/index.html.twig — so
 * `aria-current` is not a signal that exists on a healthy installation.
 *
 * And it is the section whose EMPTY state is not the one worth measuring. That
 * gap is not hypothetical: the first version of this spec measured health in
 * isolation, got the all-clear pane, passed, and shipped a 4px defect in the
 * issue cards that only appeared in a full-suite run, where other specs happen
 * to break accounts. So this spec now breaks one itself (see `beforeAll`) and
 * asserts on `[data-health-issue]` — the feed, not the all-clear. If the seeding
 * ever stops working the assertion fails by name instead of quietly measuring
 * the empty variant again.
 */
const MUST_RENDER: Record<string, string> = { health: "[data-health-issue]" };

/**
 * This worker's own mail account, and the SQL that scopes every write to it.
 *
 * Lifted deliberately from account-health.spec.ts rather than reinvented, down
 * to the quoting: every worker's seeded account is called "E2E Mailbox", so a
 * bare `WHERE email = 'E2E Mailbox'` is one row per parallel slot and this spec
 * would break and "restore" accounts belonging to workers it has never heard of.
 * That is not a hypothetical either — it is written up at length over there, and
 * the symptom was other specs seeing a health badge nobody asked for.
 *
 * The doubled backslashes are one backslash each by the time the string exists,
 * which the double-quoted shell argument then hands to Postgres as `"user"` —
 * the word is reserved, and its quotes have to survive a template literal, a
 * shell and the parser in that order.
 */
const OWNED_BY_THIS_WORKER =
    `email = 'E2E Mailbox' AND usr_id = (SELECT id FROM \\"user\\" WHERE email = '${TEST_USER.email}')`;

/**
 * Puts this worker's account into the state that renders the issue feed.
 *
 * An OAuth account whose stored refresh error is `invalid_grant` while it is
 * still marked active — the condition the live install was found in, and one
 * HealthReport rates Critical, so the card it produces carries the widest
 * severity accent there is (`border-l-4`). That is the card whose 4px this spec
 * previously could not see, so it is the right one to force.
 *
 * NOT seedUser(): re-seeding re-hashes the password, Symfony decides the user
 * changed, and the session is dropped — every subsequent goto then lands on
 * /login. The workerAuth fixture has already created this account by the time a
 * beforeAll runs, so there is nothing to seed, only something to break.
 */
function breakTheAccount(): void {
    consoleCommand(
        `dbal:run-sql "UPDATE account SET auth_type = 'oauth2', oauth_provider = 'google', ` +
            `oauth_access_token = 'stale', oauth_refresh_token = 'stale', ` +
            `oauth_last_refresh_error = 'invalid_grant' WHERE ${OWNED_BY_THIS_WORKER}"`,
    );
}

/**
 * Puts it back, so the specs that assert on account lists find what they expect.
 *
 * In afterAll rather than afterEach because there is one test, and Playwright
 * runs afterAll even when that test fails — which matters here, since the run
 * this hook cleans up after is most likely to be a failing one.
 */
function restoreTheAccount(): void {
    consoleCommand(
        `dbal:run-sql "UPDATE account SET auth_type = 'password', oauth_provider = NULL, ` +
            `oauth_access_token = NULL, oauth_refresh_token = NULL, ` +
            `oauth_last_refresh_error = NULL WHERE ${OWNED_BY_THIS_WORKER}"`,
    );
}

test.beforeAll(breakTheAccount);
test.afterAll(restoreTheAccount);

/** The content column: the second grid child, holding the cards and nothing else. */
const WRAPPER = ".min-w-0.-mx-6";

/** How far apart two edges may be before it is a defect and not a rounding. */
const TOLERANCE = {
    /** Band against body, inside one card: both sit inside the same border. */
    withinCard: 1,
    /** Card against card, each measured from its own edge. */
    betweenCards: 1,
    /** A bare heading against a card, which is one border-width further in. */
    aroundCards: 2,
};

/**
 * Opens a section and refuses to measure anything else.
 *
 * Two ways this navigation can look like it worked and not have:
 *
 *   - The section fell back. `SettingsController::index` maps an unknown
 *     `?section=` to `profile` rather than 404ing, so a renamed section returns
 *     200 with a perfectly good page that is not the one asked for. The nav
 *     marks the live section with `aria-current="page"`, so reading the href off
 *     that link is the cheap way to ask the server what it thinks it rendered.
 *
 *   - The session went. This is not hypothetical and it is not a flake: another
 *     Playwright run re-seeding this worker's user re-hashes the password,
 *     Symfony decides the user changed and drops the session, and every
 *     subsequent goto lands on /login — which is a 200, has the right title, and
 *     contains exactly one `.rounded-pane`, so a sweep measures the login card,
 *     finds nothing wrong with it and passes. It is the same trap
 *     account-health.spec.ts documents at length. Asserting the settings content
 *     column exists turns a silent pass into a named failure.
 */
async function openSection(page: Page, section: string): Promise<void> {
    await page.goto(`/settings?section=${section}`);

    await expect(
        page.locator(WRAPPER),
        `the settings content column is missing on ?section=${section} — ` +
            `if this is every section, the session was dropped and this ran against /login`,
    ).toBeAttached();

    const container = MUST_RENDER[section];

    if (undefined !== container) {
        await expect(
            page.locator(container).first(),
            `?section=${section} rendered, but not the state worth measuring (${container})`,
        ).toBeVisible();

        return;
    }

    await expect(
        page.locator('nav a[aria-current="page"]'),
        `?section=${section} did not render that section — an unknown section falls back to profile`,
    ).toHaveAttribute("href", new RegExp(`section=${section}$`));
}

/**
 * Waits out the lazy turbo-frames.
 *
 * Integrations loads its list through a frame so every mutation can replace it,
 * and until that arrives the card's body is the loading placeholder — which is
 * `py-10 text-center` with no horizontal inset at all, because it is centred
 * text that never needed one. Measured mid-flight it reads as a body 16px out
 * from its own header, which is a true statement about the placeholder and a
 * false one about the card.
 *
 * Every frame that has a `src`, rather than a list of frame ids, for the reason
 * the admin twin gives: it covers every frame on every section without naming
 * any of them, so a section that grows one later is waited on without this file
 * changing. Turbo stamps `complete` on a frame once its fetch has landed, which
 * is the signal being read.
 *
 * NOT "no spinner left in the column", which is what the admin twin can afford
 * to say. Filters renders a spinner for a rule that is genuinely RUNNING — a run
 * outlives the tab that started it, so the row shows its state from the database
 * — and that spinner never goes away. Waiting on it hangs for five seconds and
 * then reports a loading failure about a page that had finished loading.
 */
async function settle(page: Page): Promise<void> {
    const frames = page.locator(`${WRAPPER} turbo-frame[src]`);

    for (let index = 0; index < (await frames.count()); index++) {
        await expect(frames.nth(index)).toHaveAttribute("complete", "");
    }
}

/** What one section measured. */
type Measurement = {
    offences: string[];
    cards: number;
};

async function measure(page: Page, section: string): Promise<Measurement> {
    return page.evaluate(
        ({ section, wrapper, tolerance }) => {
            const offences: string[] = [];

            const round = (value: number) => Math.round(value * 10) / 10;

            const visible = (el: Element): boolean => {
                const box = el.getBoundingClientRect();

                return 0 < box.width && 0 < box.height;
            };

            /**
             * The x where a block's content begins.
             *
             * Its own left edge plus its padding — or, when it has none, the
             * first thing inside it that does. Identical to the admin twin, and
             * for the same reason: a card body here is a padded box
             * (chrome.body()), a bare wrapper around rows that pad themselves,
             * or a turbo-frame around either, and descending until something is
             * padded reads all of them without having to know which.
             */
            const contentStart = (el: Element): number => {
                let node: Element | null = el;

                for (let depth = 0; null !== node && depth < 8; depth++) {
                    const padding = parseFloat(getComputedStyle(node).paddingLeft) || 0;

                    if (0 < padding) {
                        return node.getBoundingClientRect().left + padding;
                    }

                    node = Array.from(node.children).find(visible) ?? null;
                }

                return el.getBoundingClientRect().left;
            };

            /**
             * Where a pane's content begins measured from its PADDING box —
             * the same descent as contentStart, with every border crossed on
             * the way subtracted back out.
             *
             * This exists because of the health severity accent. A health issue
             * card is `border-l-4 border-l-red-500` (HealthSeverity::cardClasses)
             * — a 4px stripe encoding critical/warning/notice — over the ordinary
             * `px-4` body. Its text therefore starts 20px inside its own edge
             * where every other card starts 17px inside its own, and check C
             * read that as the heading above the feed sitting 4px out of line.
             *
             * The heading is not out of line, and this is the one measurement
             * where that can be proved rather than argued: making it match a
             * border-inclusive reference would mean insetting it by 20px, which
             * is px-5, which the STATIC guard rejects as a hand-rolled card
             * inset. The two guards would then contradict each other and no
             * markup could satisfy both. When a rule cannot be satisfied, the
             * rule is what is wrong.
             *
             * So what has to agree is padding against padding: every card insets
             * its content 16px from its padding box, and a heading outside the
             * cards is inset 16px too. A decorative stripe is drawn OUTSIDE that
             * agreement and must not drag the comparison rightward — a card is
             * free to carry any border it likes without the prose above it
             * having to follow.
             */
            const paddingInset = (el: Element): number => {
                let node: Element | null = el;
                let borders = 0;

                for (let depth = 0; null !== node && depth < 8; depth++) {
                    const style = getComputedStyle(node);
                    const padding = parseFloat(style.paddingLeft) || 0;

                    if (0 < padding) {
                        return node.getBoundingClientRect().left + padding - el.getBoundingClientRect().left - borders;
                    }

                    // Only counted once we descend THROUGH this node: a border
                    // on the element that carries the padding is already
                    // outside its own getBoundingClientRect().left.
                    borders += parseFloat(style.borderLeftWidth) || 0;

                    node = Array.from(node.children).find(visible) ?? null;
                }

                return 0;
            };

            /** What to call a card in a failure message. */
            const name = (card: Element): string => {
                const heading = card.querySelector("h2, h3");
                const text = heading?.textContent?.trim();

                return text && "" !== text ? text : card.id || card.className.split(" ")[0];
            };

            const frame = document.querySelector(wrapper);

            if (null === frame) {
                return { offences: [`${section}: the settings content column never arrived`], cards: 0 };
            }

            const panels = Array.from(frame.querySelectorAll(".rounded-pane")).filter(visible);

            // `bg-surface` is the class that separates a card from a segmented
            // control — see the note at the top. The tinted health issue cards
            // are deliberately outside this list too: they carry a severity
            // wash instead of the macro's own surface and stack as a feed, so
            // they are measured by check C's reference below and by the static
            // counterpart, exactly as the admin sweep treats its tinted cards.
            const cardList = panels.filter(
                (panel) =>
                    panel.classList.contains("overflow-hidden") && panel.classList.contains("bg-surface"),
            );

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
                                `The band and the body must both come from _partials/_card_chrome.html.twig ` +
                                `(settings/_card.html.twig does this for you).`,
                        );
                    }
                }
            }

            // B. The cards against each other. Measured from each card's own
            // edge, because Appearance legitimately sits its two cards side by
            // side at different absolute x.
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

            // C. What sits between and above the cards. A section heading or an
            // explanatory paragraph outside every pane has to begin where the
            // panes' content begins, or the page reads as two columns.
            //
            // Measured from the element's LEFT EDGE, not from its padding: the
            // offence this was written for cancelled its padding above a
            // breakpoint, and a scan of padded nodes cannot see a node with no
            // padding. Headings and paragraphs only — every wrapper between them
            // shares their left edge and would repeat the same report.
            //
            // The reference is the first pane flush with the column, INCLUDING
            // the tinted ones: on health the flush panes are the issue cards
            // themselves, and they are what its heading has to line up with.
            //
            // A consequence card is never the reference and that is correct
            // rather than convenient: `@2xl:ml-6` indents it under the cause it
            // belongs to — real hierarchy, one dead sign-in not reading as nine
            // emergencies — so it is 24px off the column and fails the flush
            // test by construction. The cause above it is flush and is what the
            // heading is measured against.
            const flush = panels.filter(
                (panel) => 1 >= Math.abs(panel.getBoundingClientRect().left - frame.getBoundingClientRect().left),
            );

            if (0 === flush.length) {
                return { offences, cards: cardList.length };
            }

            // Padding box, not border box — see paddingInset. A card's stripe is
            // its own business; its INSET is what the page has to agree on.
            const reference = flush[0].getBoundingClientRect().left + paddingInset(flush[0]);

            for (const node of Array.from(frame.querySelectorAll("h1, h2, h3, p")).filter(visible)) {
                if (null !== node.closest(".rounded-pane")) {
                    continue;
                }

                const text = (node.textContent ?? "").trim();

                if ("" === text) {
                    continue;
                }

                const start = node.getBoundingClientRect().left;
                const drift = Math.abs(start - reference);

                if (drift > tolerance.aroundCards) {
                    offences.push(
                        `${section}: "${text.slice(0, 40)}" starts at x=${round(start)} ` +
                            `but the cards' content starts at x=${round(reference)} — ${round(drift)}px apart. ` +
                            `A heading or paragraph outside the cards takes the same px-4 the cards do, ` +
                            `at every width. Either end can be the wrong one: check the heading for a ` +
                            `responsive px-0 that cancels its inset, and the card body for a px-5. ` +
                            `The reference is measured from the card's PADDING box, so a decorative ` +
                            `border — the health severity stripe is border-l-4 — is not what moved it.`,
                    );
                }
            }

            return { offences, cards: cardList.length };
        },
        { section, wrapper: WRAPPER, tolerance: TOLERANCE },
    );
}

test("every settings section lines its cards up, inside and against each other", async ({ page }) => {
    // Eighteen sections, each a full navigation.
    test.setTimeout(120_000);

    const offences: string[] = [];
    let cards = 0;

    // Every section in one test, and every offence collected before anything is
    // asserted — the admin twin's reasoning applies unchanged. Stopping at the
    // first bad card would report one of them and hide the rest, which is how
    // this came to be reported four times.
    for (const section of SECTIONS) {
        await openSection(page, section);
        await settle(page);

        const measured = await measure(page, section);

        offences.push(...measured.offences);
        cards += measured.cards;
    }

    // The selector still finds cards. Without this a `.rounded-pane` renamed in
    // the stylesheet, or a `bg-surface` dropped from chrome.shell(), would empty
    // the sweep and leave a test that passes by measuring nothing.
    expect(cards, "the sweep found no settings cards at all — has the card markup changed?").toBeGreaterThan(15);

    expect(offences.join("\n"), "settings cards disagree about where their content starts").toBe("");
});
