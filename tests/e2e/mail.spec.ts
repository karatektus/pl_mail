import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

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

        // Hover-only actions need the row hovered first.
        await row.hover();
        await row.getByRole("button", { name: "Archive", exact: true }).click();

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

        await row.hover();
        await row.getByRole("button", { name: "Archive", exact: true }).click();
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

        await row.hover();
        await row.getByRole("button", { name: "Delete", exact: true }).click();

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

        await row.hover();

        // Wait for the status POST itself, then reload — the read-state stream
        // has a known template typo (flagged separately), so assert on the
        // persisted outcome rather than the inline swap.
        const readPost = page.waitForResponse(
            (r) =>
                r.request().method() === "POST" &&
                /\/status\/thread\/\d+\/read$/.test(r.url()),
        );
        await row.getByRole("button", { name: "Mark as read", exact: true }).click();
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

        // Step 1 — mark read.
        let row = mailRow(page, subject);
        await expect(row).toHaveAttribute("data-unread", "true");
        await row.hover();
        let post = page.waitForResponse(
            (r) => r.request().method() === "POST" && readEndpoint.test(r.url()),
        );
        await row.getByRole("button", { name: "Mark as read", exact: true }).click();
        await post;
        await page.reload();

        // Step 2 — the same row now offers "Mark as unread".
        row = mailRow(page, subject);
        await expect(row).toHaveAttribute("data-unread", "false");
        await row.hover();
        post = page.waitForResponse(
            (r) => r.request().method() === "POST" && readEndpoint.test(r.url()),
        );
        await row
            .getByRole("button", { name: "Mark as unread", exact: true })
            .click();
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
     * second is why mail_pane_controller#_serverOwns exists.
     */
    test("a sync refresh leaves an open row menu open", async ({ page }) => {
        await page.goto("/mail/inbox");

        const row = mailRow(page, INBOX_SUBJECTS.read);
        await row.hover();
        await row.getByRole("button", { name: "Snooze" }).click();

        const menu = row.locator('[data-ui--dropdown-target="menu"]');
        await expect(menu).toBeVisible();

        await syncFired(page);

        await expect(menu).toBeVisible();

        // And still a working menu, not just a visible one — the controller
        // instance survived with its element.
        await expect(menu.getByText("Later today")).toBeVisible();
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
});
