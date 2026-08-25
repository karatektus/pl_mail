import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { settled } from "./support/motion";
import { rowAction } from "./support/rows";

/**
 * Runs authenticated as this worker's own user, signed in by the worker
 * fixture in support/test.ts.
 *
 * A fresh, deterministic inbox is reseeded before each test via the
 * `app:test:seed-mail` console command (Gmail-style messages: label
 * mutations only, no IMAP folder). Per-test reseeding keeps the cases
 * fully independent and retry-safe even though they mutate shared data.
 *
 * Override E2E_CONSOLE if `php` isn't on PATH (e.g. "symfony console").
 */
test.beforeEach(() => {
    seed("seed-mail");
});

test.describe("mail UI actions", () => {
    /**
     * The row's who column lists everyone who has written in the conversation,
     * oldest first. It used to show the newest sender, so answering a mail
     * relabelled the whole conversation as coming from you.
     */
    test("keeps the correspondent in the row after you reply", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.read);
        await expect(row).toContainText("E2E Sender");

        await row.click();
        await page.getByRole("link", { name: "Reply", exact: true }).first().click();
        await page
            .locator('#compose_inline [data-compose--compose-toolbar-target="editor"]')
            .fill("Reply draft body");
        await page.waitForResponse((r) =>
            r.url().includes("/compose/draft") && r.request().method() === "POST"
        );

        await page.goto("/mail/inbox");

        // The same row, not a new one called "Re: …". The reply joins the
        // conversation it answers, so the list keeps showing the thread's own
        // subject and simply counts one message more — looking for a "Re:" row
        // was looking for a row this app never renders.
        const replied = mailRow(page, INBOX_SUBJECTS.read);
        await expect(replied).toContainText("E2E Sender, me");
    });

    test("stars a conversation and it shows in the Starred view", async ({
                                                                             page,
                                                                         }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.star);
        await expect(row).toBeVisible();

        await row.getByRole("button", { name: "Star this message" }).click();

        // _star.stream replaces the row; the toggle flips to "Unstar".
        await expect(
            row.getByRole("button", { name: "Unstar this message" }),
        ).toBeVisible();

        // And the conversation now appears under Starred.
        await page.goto("/mail/starred");
        await expect(
            page
                .locator('#message-list li[data-controller="mail--message-row"]')
                .filter({ hasText: INBOX_SUBJECTS.star }),
        ).toBeVisible();
    });

    test("archives a conversation and it leaves the inbox", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        await expect(row).toBeVisible();

        await rowAction(row, "Archive");

        // _archive.stream removes the row.
        await expect(row).toHaveCount(0);

        // Still gone after a reload (Inbox label removed, DB is source of truth).
        await page.reload();
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);

        // And it has to be SOMEWHERE. Archiving used to remove the Inbox label
        // and add nothing, while the Archive view asks for a label whose role is
        // Archive — so the conversation left the inbox and arrived nowhere. This
        // half of the assertion is the one that was missing.
        await page.goto("/mail/archive");
        await expect(
            page
                .locator('#message-list li[data-controller="mail--message-row"]')
                .filter({ hasText: INBOX_SUBJECTS.archive }),
        ).toBeVisible();
    });

    /**
     * Archiving is not filing: Gmail's rule is that the mail keeps its labels
     * and only leaves the inbox.
     */
    test("an archived conversation keeps the labels it had", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        await expect(row).toBeVisible();

        await rowAction(row, "Archive");
        await expect(row).toHaveCount(0);

        await page.goto("/mail/archive");
        await expect(
            page
                .locator('#message-list li[data-controller="mail--message-row"]')
                .filter({ hasText: INBOX_SUBJECTS.archive }),
        ).toBeVisible();
    });

    /**
     * The sidebar route into it. The "More" disclosure used to come back shut
     * on every navigation — the sidebar is not turbo-permanent and re-renders
     * whole — so choosing Archive from it read as the menu collapsing instead
     * of doing anything.
     */
    test("stays open on More after navigating to Archive", async ({ page }) => {
        await page.goto("/mail/inbox");

        const more = page.locator("#sidebar details").first();
        await more.locator("summary").click();

        await page.locator('#sidebar a[href="/mail/archive"]').click();
        await page.waitForURL(/\/mail\/archive/);

        // The disclosure is open on arrival, with the row you chose still shown.
        await expect(page.locator("#sidebar details").first()).toHaveAttribute("open", "");
        await expect(page.locator('#sidebar a[href="/mail/archive"]')).toBeVisible();
    });

    test("deletes a conversation and it moves to Trash", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.trash);
        await expect(row).toBeVisible();

        await rowAction(row, "Delete");

        // _delete.stream removes the row from the inbox.
        await expect(row).toHaveCount(0);

        // Trash adds the Trash-role label, so it surfaces in the Trash view.
        await page.goto("/mail/trash");
        await expect(
            page
                .locator('#message-list li[data-controller="mail--message-row"]')
                .filter({ hasText: INBOX_SUBJECTS.trash }),
        ).toBeVisible();
    });

    test("marks a conversation as read", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.read);
        await expect(row).toBeVisible();
        await expect(row).toHaveAttribute("data-unread", "true");

        // Wait for the status POST itself, then reload — the read-state stream
        // has a known template typo (flagged separately), so assert on the
        // persisted outcome rather than the inline swap.
        const readPost = page.waitForResponse(
            (r) =>
                r.request().method() === "POST" &&
                /\/status\/thread\/\d+\/read$/.test(r.url()),
        );
        await rowAction(row, "Mark as read");
        await readPost;

        await page.reload();
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveAttribute(
            "data-unread",
            "false",
        );
    });

    test("marks a read conversation back to unread", async ({ page }) => {
        await page.goto("/mail/inbox");

        // Round-trip on a seeded (unread) thread: read, then unread. Reloading
        // between steps keeps this independent of the read-stream inline swap.
        const subject = INBOX_SUBJECTS.read;
        const readEndpoint = /\/status\/thread\/\d+\/read$/;

        // Both steps wait for the list to stop moving before hovering, and the
        // reason is specific to a VERTICAL entrance. Each row is parked 48px
        // above its place while it waits its turn in the cascade — see
        // MotionLevel::listStagger() — and a parked row is a still row, so
        // hover() is happy to aim at it. A moment later the row drops into
        // place and the pointer is over its neighbour, group-hover stops
        // matching, and the action that hover was meant to reveal never becomes
        // visible. The horizontal entrance this replaced could not do that: a
        // row moving sideways keeps its own band of the screen.
        await settled(page);

        // Step 1 — mark read.
        let row = mailRow(page, subject);
        await expect(row).toHaveAttribute("data-unread", "true");
        let post = page.waitForResponse(
            (r) => r.request().method() === "POST" && readEndpoint.test(r.url()),
        );
        await rowAction(row, "Mark as read");
        await post;
        await page.reload();
        await settled(page);

        // Step 2 — the same row now offers "Mark as unread".
        row = mailRow(page, subject);
        await expect(row).toHaveAttribute("data-unread", "false");
        post = page.waitForResponse(
            (r) => r.request().method() === "POST" && readEndpoint.test(r.url()),
        );
        await rowAction(row, "Mark as unread");
        await post;
        await page.reload();

        await expect(mailRow(page, subject)).toHaveAttribute("data-unread", "true");
    });

    // ── Bulk toolbar actions ─────────────────────────────────────────────────
    //
    // Written against observable outcomes rather than the endpoint, so they
    // hold however the bulk mutation is implemented — per-row streams or a
    // frame reload. (They carried a notice saying they would fail until the
    // toolbar handlers stopped being console.log stubs, with an instruction to
    // delete it once green. They are green.)

    const allRows = (page: Page) =>
        page.locator('#message-list li[data-controller="mail--message-row"]');

    /**
     * The four rows this spec seeded are on screen.
     *
     * NOT a count of the whole list, which is what these tests used to open
     * with. `seed-mail` wipes and refills one account — mailbox@e2e.test — but
     * the inbox is unified, and the same user also owns the demo mailbox the
     * screenshot tour seeds (a second account, ten threads, never cleaned up).
     * So the moment anybody ran the screenshots, every one of these tests
     * failed on its FIRST line, by exactly ten, having tested nothing.
     *
     * The precondition that matters is only that the seed landed. What the
     * bulk actions then do to the rest of the mailbox is asserted after the
     * fact, where "none left" is true however many there were.
     */
    const expectSeededRows = async (page: Page) => {
        for (const subject of Object.values(INBOX_SUBJECTS)) {
            await expect(mailRow(page, subject)).toBeVisible();
        }
    };

    const selectAll = async (page: Page) => {
        await page
            .getByRole("checkbox", { name: "Select all conversations" })
            .click();
        // Actions slot swaps in only once ≥1 row is selected.
        await expect(page.locator('[data-mail--list-toolbar-target="actions"]')).toBeVisible();
    };

    const bulkAction = (page: Page, name: string) =>
        page
            .locator('[data-mail--list-toolbar-target="actions"]')
            .getByRole("button", { name, exact: true });

    test("bulk-archives every selected conversation", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expectSeededRows(page);

        await selectAll(page);
        await bulkAction(page, "Archive").click();

        await expect(allRows(page)).toHaveCount(0);
        await page.reload();
        await expect(allRows(page)).toHaveCount(0);
    });

    test("bulk-deletes every selected conversation into Trash", async ({
                                                                           page,
                                                                       }) => {
        await page.goto("/mail/inbox");
        await expectSeededRows(page);

        await selectAll(page);
        await bulkAction(page, "Delete").click();

        await expect(allRows(page)).toHaveCount(0);

        await page.goto("/mail/trash");
        for (const subject of Object.values(INBOX_SUBJECTS)) {
            await expect(
                page
                    .locator('#message-list li[data-controller="mail--message-row"]')
                    .filter({ hasText: subject }),
            ).toBeVisible();
        }
    });

    test("bulk-marks every selected conversation as read", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expectSeededRows(page);

        await selectAll(page);
        await bulkAction(page, "Mark as read").click();

        for (const subject of Object.values(INBOX_SUBJECTS)) {
            await expect(mailRow(page, subject)).toHaveAttribute(
                "data-unread",
                "false",
            );
        }
    });

    test("bulk-marks every selected conversation as unread", async ({ page }) => {
        // Seeded threads start unread, so make them read first, then unread.
        await page.goto("/mail/inbox");
        await expectSeededRows(page);

        await selectAll(page);
        await bulkAction(page, "Mark as read").click();
        for (const subject of Object.values(INBOX_SUBJECTS)) {
            await expect(mailRow(page, subject)).toHaveAttribute(
                "data-unread",
                "false",
            );
        }

        await selectAll(page);
        await bulkAction(page, "Mark as unread").click();
        for (const subject of Object.values(INBOX_SUBJECTS)) {
            await expect(mailRow(page, subject)).toHaveAttribute(
                "data-unread",
                "true",
            );
        }
    });

    /**
     * Two bulk actions in a row, with the first one's refresh landing between
     * them.
     *
     * Every bulk action ends by re-reading the list frame from the server
     * (mail--mail-pane#release), so doing a second one straight after the first
     * means pressing a button while a fetch fired by the previous press is in
     * flight. That refresh used to assign over the whole frame — toolbar
     * included — which took the button out of the DOM under the pointer and
     * emptied the selection on the way past. The test above caught it only
     * sometimes, and only in its loud form: a click that lands on a detached
     * node throws, but a click that lands on a live button whose selection was
     * silently cleared does not, and that one is the worse bug — the person
     * sees the button depress and nothing happen.
     *
     * So the race is made deterministic rather than waited for. The refresh is
     * held at the network until the selection for the second action has been
     * made, then released, and only then is the second action pressed. Both
     * halves are asserted: that the selection is still there, and that the
     * action it was made for actually took effect.
     */
    test("a second bulk action survives the first one's refresh landing on it", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");
        await expectSeededRows(page);

        // Held, not delayed: a timer would be the same race with a longer fuse.
        let landRefresh: () => void = () => {};
        const refreshHeld = new Promise<void>((resolve) => {
            landRefresh = resolve;
        });

        // The fragment header is the pane's signature — see
        // mail_pane_controller's FRAGMENT_HEADER. Everything else goes straight
        // through, including the page load above and the bulk POSTs.
        await page.route("**/mail/inbox**", async (route) => {
            if (route.request().headers()["x-list-fragment"] === undefined) {
                return route.continue();
            }

            await refreshHeld;

            return route.continue();
        });

        const rows = page.locator('#inbox-list-frame input[data-thread-select]');
        const rowCount = await rows.count();
        expect(rowCount, "the fixture has to seed rows to select").toBeGreaterThan(0);

        // ── First action, whose refresh is now stuck at the network ──────────
        await selectAll(page);
        await bulkAction(page, "Mark as read").click();
        for (const subject of Object.values(INBOX_SUBJECTS)) {
            await expect(mailRow(page, subject)).toHaveAttribute("data-unread", "false");
        }

        // ── Select for the second action, THEN let the refresh land ──────────
        await selectAll(page);

        const refreshLanded = page.waitForResponse(
            (response) => response.request().headers()["x-list-fragment"] !== undefined,
        );
        landRefresh();
        await refreshLanded;

        // A refresh is not the user's action and must not undo their selection.
        // This is what fails first when the frame is swapped wholesale: the
        // rebuilt toolbar comes back from the server with nothing selected.
        await expect(
            page.locator('[data-mail--list-toolbar-target="selectionCount"]'),
        ).toHaveText(String(rowCount));
        await expect(
            page.locator('[data-mail--list-toolbar-target="actions"]'),
        ).toBeVisible();

        // ── Second action: the outcome, not the click ────────────────────────
        await bulkAction(page, "Mark as unread").click();
        for (const subject of Object.values(INBOX_SUBJECTS)) {
            await expect(mailRow(page, subject)).toHaveAttribute("data-unread", "true");
        }
    });

    // ── What a refresh is allowed to destroy ─────────────────────────────────

    /**
     * Ask the pane to refresh the way a sync does, without touching the page.
     *
     * The bulk-action route into a refresh is no good for these two: it needs a
     * click, and a click anywhere is exactly what closes a menu. This is the
     * event the body already routes to the pane — see the data-action in
     * _layout/app.html.twig — carrying the same `poll` flag the connection
     * fallback sends, which means "refresh whatever view is open".
     */
    const syncFired = async (page: Page) => {
        const landed = page.waitForResponse(
            (response) => response.request().headers()["x-list-fragment"] !== undefined,
        );

        await page.evaluate(() => {
            document.body.dispatchEvent(
                new CustomEvent("core--mercure:mailbox-synced", {
                    detail: { poll: true },
                    bubbles: true,
                }),
            );
        });

        await landed;
    };

    /**
     * The rows survive a refresh as the same DOM nodes.
     *
     * Marked with a PROPERTY, which is the only marker that proves anything
     * here: the morph syncs attributes from the server's markup, so an
     * attribute surviving would say nothing about whether the node did. A
     * property can only still be there if nobody rebuilt the element.
     *
     * This is what the whole morph is for. While the rows region was assigned
     * over, every refresh made fifty new nodes carrying the same mail — which
     * is why "new mail" could not be told from "the list redrew", and why the
     * two tests below had nothing to stand on.
     */
    test("a sync refresh keeps the rows it is not changing", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expectSeededRows(page);

        const before = await allRows(page).count();
        expect(before, "the fixture has to seed rows").toBeGreaterThan(0);

        await page.evaluate(() => {
            document
                .querySelectorAll('#message-list li[data-controller="mail--message-row"]')
                .forEach((row, index) => {
                    Object.assign(row, { __probe: index });
                });
        });

        await syncFired(page);

        const probes = await page.evaluate(() =>
            Array.from(
                document.querySelectorAll('#message-list li[data-controller="mail--message-row"]'),
                (row) => (row as unknown as { __probe?: number }).__probe,
            ),
        );

        expect(probes).toEqual(Array.from({ length: before }, (_, index) => index));

        // Still one list. Handing the morph the fresh <ul> whole rather than
        // its children nests a duplicate inside the live one, a level deeper
        // every refresh — and every other assertion here still passes, because
        // the rows morph correctly and simply live further down. Cheap to
        // check, and it is the shape of the mistake, not one instance of it.
        await expect(page.locator('[data-list-region="rows"]')).toHaveCount(1);
    });

    /**
     * A menu somebody is reading is not the refresh's to close.
     *
     * Two separate ways this used to fail and now does not: the whole menu was
     * destroyed with the row it hangs off, and — once the row survived — the
     * morph would have put back the `hidden` the server always renders. The
     * second is why mail_pane_controller#_mayMorph, and the MENU selector it
     * reads, exist.
     */
    test("a sync refresh leaves an open row menu open", async ({ page }) => {
        await page.goto("/mail/inbox");
        await settled(page);

        const row = mailRow(page, INBOX_SUBJECTS.read);
        await rowAction(row, "Snooze");

        const menu = row.locator('[data-ui--dropdown-target="menu"]');
        await expect(menu).toBeVisible();

        await syncFired(page);

        await expect(menu).toBeVisible();

        // And still a working menu, not just a visible one — the controller
        // instance survived with its element. Two things say so, and neither is
        // the menu's own `hidden`: the option is still un-hidden, which only
        // mail--snooze-menu ever does because the server renders every option
        // `hidden`, and it still carries the wake time that controller wrote
        // into it, which the server renders empty. A morph that reached inside
        // the open menu would have put both of those back.
        //
        // "Tomorrow" rather than "Later today", and the difference is not
        // cosmetic: "Later today" is the one option that comes and goes.
        // snooze_options.js drops it once 18:00 has passed, so a menu opened in
        // the evening correctly has no such entry, and this test — which is
        // about the refresh and not about the clock — failed for that reason
        // alone. It is what took down the v0.1.2 release run: the browser's
        // clock in CI is UTC, the tag was pushed at 17:48Z, and the suite
        // reached this test after 18:00Z, so it failed on the retry too. The
        // other three options are offered at every hour of the day and prove
        // exactly the same thing about the morph.
        const tomorrow = menu.locator('[data-snooze-key="tomorrow"]');

        await expect(tomorrow).toBeVisible();
        await expect(tomorrow.locator("[data-snooze-when]")).not.toBeEmpty();
    });

    /**
     * A whole list is its rows arriving, not a rectangle fading.
     *
     * The <ul> names the entrance and motion.js hands it to each row — see
     * ENTER_CHILDREN — which is also what stops the row's OWN entrance, the
     * long drop that means one new mail, from firing fifty times on a folder
     * change. The two gestures share a template and are told apart at runtime,
     * so the thing worth asserting is which one actually played.
     *
     * The listener is installed before any of the page's own scripts, because
     * this animation starts during load and there is no later moment to catch
     * it from.
     */
    test("a whole list arrives as its rows, one after another", async ({ page }) => {
        await page.addInitScript(() => {
            const played: string[] = [];

            Object.assign(window, { __played: played });
            document.addEventListener(
                "animationstart",
                (event) => played.push((event as AnimationEvent).animationName),
                true,
            );
        });

        await page.goto("/mail/inbox");
        await expectSeededRows(page);

        // Polled, not read once: the rows are staggered, so the later ones have
        // not started yet at the moment the first one is on screen.
        await expect
            .poll(() => page.evaluate(() =>
                (window as unknown as { __played: string[] }).__played
                    .filter((name) => name === "plmail-slide-down").length,
            ))
            .toBeGreaterThan(1);

        const played = await page.evaluate(
            () => (window as unknown as { __played: string[] }).__played,
        );

        expect(played, "the list itself must not animate as a block").not.toContain("plmail-fade");

        // Staggered, and from the list's vocabulary rather than the row's: the
        // rows are marked as having been animated on the list's behalf, which
        // is what the stylesheet keys the timings off.
        const rows = await page.evaluate(() =>
            Array.from(
                document.querySelectorAll('[data-list-region="rows"] > li'),
                (row) => ({
                    scope: row.getAttribute("data-enter-scope"),
                    delay: getComputedStyle(row).animationDelay,
                    duration: getComputedStyle(row).animationDuration,
                }),
            ),
        );

        expect(rows.length).toBeGreaterThan(1);
        expect(rows.every((row) => row.scope === "list")).toBe(true);
        // The list's own duration, not the row's 0.6s — under two frames, so
        // what is seen is the cascade below rather than any row's journey.
        expect(rows.every((row) => row.duration === "0.03s")).toBe(true);
        expect(rows[0].delay).toBe("0s");
        expect(rows[1].delay).not.toBe("0s");
    });

    /**
     * New mail arriving, staged.
     *
     * There is no way to make real mail land mid-test, so the refresh the pane
     * asks for on its own is answered with a list that has one more row in it
     * than the one on screen — which is precisely what a sync that found
     * something looks like from the browser's side.
     *
     * The row is copied rather than composed, so it carries every attribute the
     * real template emits and the test cannot drift away from it. Only the id
     * changes, because the id is the entire question: it is what tells the page
     * this is an arrival and not a redraw.
     */
    const arrivingMail = async (page: Page) => {
        await page.route("**/mail/inbox**", async (route) => {
            if (route.request().headers()["x-list-fragment"] === undefined) {
                return route.continue();
            }

            const response = await route.fetch();
            const body = await response.text();

            // `<li` with the boundary, or this finds `<link>` in the head —
            // which produced a fixture that duplicated half the document and
            // sent a morph bug hunt after a bug that was in the test.
            const opens = body.search(/<li[\s>]/);
            const closes = body.indexOf("</li>", opens) + "</li>".length;
            const row = body.slice(opens, closes);

            return route.fulfill({
                response,
                body:
                    body.slice(0, opens) +
                    row.replace(/id="thread_\d+"/, 'id="thread_99000001"') +
                    body.slice(opens),
            });
        });
    };

    /**
     * Records the movement rather than catching it mid-flight.
     *
     * Sampling a transform after the fact is a race with the animation it is
     * trying to observe, and a test that has to be quick enough is a test that
     * fails on a loaded machine. These listeners fire when the browser starts
     * the work, whenever that is, and the assertions read the tally afterwards.
     */
    const recordMotion = (page: Page) =>
        page.evaluate(() => {
            const seen = { moved: 0, entered: [] as string[] };

            Object.assign(window, { __motion: seen });

            document.addEventListener(
                "transitionrun",
                (event) => {
                    if ((event as TransitionEvent).propertyName === "transform") seen.moved++;
                },
                true,
            );
            document.addEventListener(
                "animationstart",
                (event) => {
                    seen.entered.push((event as AnimationEvent).animationName);
                },
                true,
            );
        });

    /**
     * The list makes room for mail it was handed already in place.
     *
     * Nothing is ever inserted into this list — the whole thing is refetched
     * and morphed, so the new row arrives with every row below it already one
     * row lower. The rows below therefore have to be put BACK and released, and
     * that is what `transitionrun` on `transform` is evidence of: they moved,
     * which they could only do if something replayed the gap.
     */
    test("new mail arrives into a gap the list opens for it", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expectSeededRows(page);

        await settled(page);
        await recordMotion(page);
        await arrivingMail(page);
        await syncFired(page);

        const arrival = page.locator("#thread_99000001");
        await expect(arrival).toHaveCount(1);

        // Its entrance, and the pause before it. The delay attribute is written
        // only onto rows a morph actually inserted, which is what keeps a plain
        // page load from starting late.
        await expect(arrival).toHaveAttribute("data-enter", "slide-down");
        await expect(arrival).toHaveAttribute("data-enter-delay", "");

        // Its OWN entrance, not the list's. One row landing in a list already
        // on screen is a different event from fifty arriving together, and the
        // absence of the scope marker is what says so.
        await expect(arrival).not.toHaveAttribute("data-enter-scope", "list");

        await expect
            .poll(() => page.evaluate(() =>
                (window as unknown as { __motion: { entered: string[] } }).__motion.entered,
            ))
            .toContain("plmail-slide-down");

        // And the rows below travelled to let it in.
        await expect
            .poll(() => page.evaluate(() =>
                (window as unknown as { __motion: { moved: number } }).__motion.moved,
            ))
            .toBeGreaterThan(0);

        // Nothing left behind: the inline transform and transition a FLIP needs
        // are cleared once it lands, so the next one measures a settled list.
        await expect
            .poll(() => page.evaluate(() =>
                Array.from(
                    document.querySelectorAll('#message-list li[data-controller="mail--message-row"]'),
                ).filter((row) => (row as HTMLElement).style.transform !== "").length,
            ))
            .toBe(0);
    });

    /**
     * A row falling off the end.
     *
     * On a real page this is the fifty-first conversation after new mail
     * pushed it over, or a thread that no longer belongs in the open folder.
     * Either way the server simply does not send it, so the browser learns
     * about the departure by its absence — and the row has to be held back
     * long enough to be seen leaving, because a node cannot animate after it
     * has been removed.
     */
    test("a row that falls off the end fades where it stood", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expectSeededRows(page);

        const doomed = await page.evaluate(
            () => document.querySelector('#message-list li[data-controller="mail--message-row"]')?.id,
        );
        expect(doomed).toBeTruthy();

        await settled(page);
        await recordMotion(page);

        // The same surgery as an arrival, in reverse: the refresh comes back
        // one row shorter than the list on screen.
        await page.route("**/mail/inbox**", async (route) => {
            if (route.request().headers()["x-list-fragment"] === undefined) {
                return route.continue();
            }

            const response = await route.fetch();
            const body = await response.text();
            const opens = body.search(/<li[\s>]/);
            const closes = body.indexOf("</li>", opens) + "</li>".length;

            return route.fulfill({
                response,
                body: body.slice(0, opens) + body.slice(closes),
            });
        });

        await syncFired(page);

        await expect
            .poll(() => page.evaluate(() =>
                (window as unknown as { __motion: { entered: string[] } }).__motion.entered,
            ))
            .toContain("plmail-leave");

        // And it is gone afterwards, not merely invisible — a row left behind
        // at opacity zero still takes clicks away from the row beneath it.
        await expect(page.locator(`#${doomed}`)).toHaveCount(0);
        await expect
            .poll(() => page.evaluate(() =>
                document.querySelectorAll('#message-list li[data-leaving]').length,
            ))
            .toBe(0);
    });

    /**
     * None means none, and it means it here too.
     *
     * The tier is the one place this whole feature can be turned off, so the
     * expensive path — measuring, inverting, holding a row back for its fade —
     * has to be genuinely skipped rather than merely run at zero duration.
     */
    test("the arrival gesture is skipped entirely at the none tier", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expectSeededRows(page);

        await page.evaluate(() => {
            document.documentElement.dataset.motion = "none";
        });

        await recordMotion(page);
        await arrivingMail(page);
        await syncFired(page);

        await expect(page.locator("#thread_99000001")).toHaveCount(1);

        expect(
            await page.evaluate(() =>
                (window as unknown as { __motion: { moved: number } }).__motion.moved,
            ),
        ).toBe(0);
    });

    /**
     * Hovering Reply is not asking for a reply.
     *
     * Turbo Drive prefetches a link on hover, and the pointer crosses these two
     * controls on the way to almost anything at the bottom of a mail. Each
     * prefetch rebuilt the whole compose window on the server — the form, the
     * identity's signature, the sanitised quote of the entire original — and
     * threw it away again. Nothing is written by that GET, so it never
     * corrupted anything; it just did the most expensive render in the app on
     * mouse movement.
     *
     * The click still has to work, which is the half a bare
     * `data-turbo-prefetch="false"` could get wrong, so it is asserted here
     * rather than left to the specs that open a reply by other means.
     */
    test("hovering Reply prefetches nothing, and clicking it still opens", async ({ page }) => {
        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.read).click();

        const reply = page.getByRole("link", { name: "Reply", exact: true }).first();
        await expect(reply).toBeVisible();

        let composeRequests = 0;
        page.on("request", (request) => {
            if (request.url().includes("/compose/reply")) {
                composeRequests++;
            }
        });

        await reply.hover();
        // Turbo's prefetch fires on a short hover delay, not immediately.
        await page.waitForTimeout(1000);

        expect(composeRequests, "a hover asked the server for a draft").toBe(0);

        await reply.click();
        await expect(page.locator(".compose-window").first()).toBeVisible();
        expect(composeRequests, "the click itself must still fetch one").toBe(1);
    });

    // ── Still pending: needs more than wiring ────────────────────────────────

    // Blocked: "Label as" never fires the POST from the UI. The only rendered
    // menu is the list-toolbar bulk instance, whose _resolveTargets() reads
    // `[data-thread-select]:checked` — but the row checkbox has no
    // `data-thread-select`/`value`, so it finds zero targets. And no template
    // renders _label_menu with a targetId (single-target mode). Minimal fix:
    // add `data-thread-select value="{{ rowId }}"` to the row checkbox (unblocks
    // bulk), or render _label_menu with targetId in _thread_content.html.twig
    // (unblocks single-target). This one also needs a seeded custom label to
    // click, so it stays fixme until both land.
    test.fixme("labels a conversation via the Label-as menu", async ({
                                                                         page,
                                                                     }) => {
        await page.goto("/mail/inbox");
        // TODO(app): wire a working label-menu target, then seed a custom label.
    });

    /**
     * The Label-as panel, opened inside a thread, must be whole.
     *
     * The reading pane is `overflow-hidden`, and the panel was `absolute` — so
     * in the list toolbar it was fine and in the thread it was sliced off at
     * the pane's edge, with no way to scroll to the rest, because the thing
     * clipping it is not the thing that scrolls. It is `fixed` while open now,
     * which escapes every ancestor's overflow at once.
     *
     * Asserted on geometry rather than on a class: "is it clipped" is a
     * question about boxes, and a panel can carry every intended class and
     * still be cut in half by an ancestor.
     */
    test("the Label-as menu is not clipped by the reading pane", async ({ page }) => {
        // A short window, which is the condition the bug needs: at a tall
        // viewport the panel fits below the toolbar and nothing clips it, so a
        // test at the default size passes whether the fix is there or not —
        // measured, not assumed. 420px leaves the pane shorter than the
        // panel's 288px maximum plus the chrome above it.
        await page.setViewportSize({ width: 1280, height: 420 });

        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.read).click();

        // Scoped to the READING PANE. There are two Label-as buttons on this
        // page — the list toolbar's and the thread's — and `.first()` finds the
        // list one, which lives outside the pane and was never the clipped one.
        // A test that opened it passed whether the fix was there or not.
        const pane = page.locator('[data-mail--mail-pane-target="reading"]');
        const button = pane.getByRole("button", { name: "Label as" }).first();

        await expect(button).toBeVisible();
        await button.click();

        const panel = pane.locator('[data-mail--label-menu-target="panel"]:not(.hidden)').first();
        await expect(panel).toBeVisible();

        // Hit-testing, not boundingBox(). A clipped element still REPORTS its
        // full layout box — overflow is resolved when painting, not when laying
        // out — so measuring the box proves nothing at all. What clipping
        // actually breaks is whether the pixel is there, so the question has to
        // be asked of the pixel: what does the document say is at the bottom of
        // this panel?
        const reachable = await panel.evaluate((element) => {
            const box = element.getBoundingClientRect();
            const x = box.left + box.width / 2;
            // Just inside the bottom edge, which is the end that was cut off.
            const y = box.bottom - 4;
            const hit = document.elementFromPoint(x, y);

            return {
                height: box.height,
                inside: hit !== null && (element === hit || element.contains(hit)),
            };
        });

        expect(
            reachable.inside,
            "the bottom of the panel is not reachable — something is clipping it",
        ).toBe(true);

        // And tall enough to be a menu rather than a sliver.
        expect(reachable.height).toBeGreaterThan(40);
    });
});
