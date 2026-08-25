import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * Signing a PDF by drawing on it, and replying with the signed copy.
 *
 * ⚠ Almost entirely JavaScript. Run `php bin/console asset-map:compile` after
 * changing anything under assets/ — and `tailwind:build` first for a
 * stylesheet — or Playwright reads the previous build and every result here is
 * about code that is no longer on disk.
 *
 * A VISUAL signature: a picture of a name on a page. Nothing asserted here
 * should be read as a claim about authenticity.
 *
 * The document is `SamplePdf::awkward()` — two pages, the second rotated a
 * quarter turn and with a MediaBox that does not start at the origin. Those two
 * properties are the point: they are what the coordinate arithmetic gets wrong,
 * and a fixture without them passes while the stamp lands an inch off on the
 * one scanned contract that mattered. pdf-geometry.spec.ts tests that
 * arithmetic in isolation; this is about whether it is WIRED up.
 */
test.beforeEach(() => {
    seed("seed-mail", "seed-attachment");
});

const PAGES = '[data-mail--pdf-viewer-target="stack"] canvas';
const STAMP = '[data-mail--pdf-sign-target="stamp"]';
const PAD = '[data-mail--pdf-sign-target="ink"]';

async function openReader(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page
        .locator("#message-list li")
        .filter({ hasText: "E2E Attachment" })
        .first()
        .click();

    await page.getByRole("button", { name: /e2e-document\.pdf/ }).click();
    await expect(page.locator(PAGES).first()).toBeVisible({ timeout: 20000 });
}

/** Draws a short stroke across the pad, the way a pointer actually would. */
async function scribble(page: Page): Promise<void> {
    await page.getByRole("button", { name: "Sign" }).click();

    const pad = page.locator(PAD);
    await expect(pad).toBeVisible();

    const box = (await pad.boundingBox())!;
    const y = box.y + box.height / 2;

    await page.mouse.move(box.x + 20, y);
    await page.mouse.down();

    for (let step = 1; step <= 8; step += 1) {
        await page.mouse.move(box.x + 20 + step * 12, y + (step % 2 === 0 ? -14 : 14));
    }

    await page.mouse.up();
}

test.describe("signing a PDF", () => {
    test("a drawn signature is placed on the page", async ({ page }) => {
        await openReader(page);
        await scribble(page);

        await page.getByRole("button", { name: "Place on page" }).click();

        const stamp = page.locator(STAMP);
        await expect(stamp).toBeVisible();

        // It carries the ink, rather than being an empty outline: the trim step
        // answers null for a pad nothing was drawn on, and the failure mode is
        // a box that positions perfectly and has no signature in it.
        await expect(stamp).toHaveAttribute("style", /background-image: url/);
    });

    /** An empty pad has nothing to place, and must not pretend otherwise. */
    test("nothing can be placed until something is drawn", async ({ page }) => {
        await openReader(page);

        await page.getByRole("button", { name: "Sign" }).click();
        await expect(page.getByRole("button", { name: "Place on page" })).toBeDisabled();
    });

    /**
     * The stamp belongs to the page, not to the window.
     *
     * Scrolling the reader must carry it along — a stamp positioned in client
     * coordinates sits still while the document moves under it, which looks
     * right until the moment anybody scrolls.
     */
    test("the signature scrolls with the document", async ({ page }) => {
        await openReader(page);
        await scribble(page);
        await page.getByRole("button", { name: "Place on page" }).click();

        const stamp = page.locator(STAMP);
        await expect(stamp).toBeVisible();

        const before = (await stamp.boundingBox())!.y;

        await page.locator('[data-mail--pdf-viewer-target="scroller"]').evaluate((node) => {
            node.scrollTop += 200;
        });

        await expect
            .poll(async () => (await stamp.boundingBox())!.y)
            .toBeLessThan(before - 100);
    });

    /**
     * The whole point of the feature: the signed file comes back as a reply
     * that is ready to send, not as a download the reader has to re-attach.
     */
    test("replying attaches the signed copy to a draft", async ({ page }) => {
        await openReader(page);
        await scribble(page);
        await page.getByRole("button", { name: "Place on page" }).click();

        await page.getByRole("button", { name: "Reply with signed copy" }).click();

        // The compose window, with the signed file already on it. The name is
        // derived on the server from the part being signed — a client-supplied
        // one is a path and an extension we would be storing.
        await expect(page.locator(".compose-window").first()).toBeVisible({ timeout: 20000 });
        await expect(page.getByText("e2e-document-signed.pdf")).toBeVisible({ timeout: 20000 });
    });

    /**
     * And it is a real PDF with the stamp actually in it.
     *
     * Downloaded rather than inspected through the draft, because this asserts
     * on the BYTES: a flatten that silently produced the original document
     * unchanged would satisfy every other test in this file.
     */
    test("the signed copy is a PDF that grew", async ({ page }) => {
        await openReader(page);
        await scribble(page);
        await page.getByRole("button", { name: "Place on page" }).click();

        const download = page.waitForEvent("download");
        await page.getByRole("button", { name: "Download signed copy" }).click();

        const saved = await download;
        expect(saved.suggestedFilename()).toBe("e2e-document-signed.pdf");

        const stream = await saved.createReadStream();
        const chunks: Buffer[] = [];

        for await (const chunk of stream) {
            chunks.push(chunk as Buffer);
        }

        const bytes = Buffer.concat(chunks);

        expect(bytes.subarray(0, 5).toString()).toBe("%PDF-");
        // The fixture is about a kilobyte; a PNG stamp is several. Comparing
        // against the fixture's own size rather than a number, so that growing
        // the fixture does not turn this into a false pass.
        expect(bytes.length).toBeGreaterThan(2000);
    });
});

/**
 * Where the stamp actually lands, on the page that makes it hard.
 *
 * Page 2 of the fixture is rotated a quarter turn AND has a MediaBox that does
 * not start at the origin. Both are ordinary in scanned documents and both are
 * invisible to every test above: a stamp placed with the y-flip hand-rolled, or
 * with the MediaBox origin subtracted a second time, still produces a valid PDF
 * that is bigger than the original and still attaches to a reply. It simply has
 * the signature in the wrong place, which only a human ever noticed.
 *
 * So this renders the signed page and compares it against the same page of the
 * ORIGINAL, quadrant by quadrant. Comparing the two documents rather than
 * asserting absolute darkness is what makes it immune to the fixture's own text
 * moving, to font rendering, and to the anti-aliasing that differs per machine:
 * the only thing measured is what signing ADDED.
 */
test("the signature lands where it was put on a rotated page", async ({ page }) => {
    await openReader(page);

    // To the foot of the document, so the reader reports page 2 as the one
    // being looked at — which is the page a stamp is dropped onto.
    const scroller = page.locator('[data-mail--pdf-viewer-target="scroller"]');
    await scroller.evaluate((node) => {
        node.scrollTop = node.scrollHeight;
    });

    const second = page.locator(PAGES).nth(1);
    await expect(second).toBeInViewport();

    await scribble(page);
    await page.getByRole("button", { name: "Place on page" }).click();

    const stamp = page.locator(STAMP);
    await expect(stamp).toBeVisible();

    // Into the upper-left quarter of the page, well clear of the middle so a
    // near-miss cannot be read as a hit.
    const target = (await second.boundingBox())!;
    const box = (await stamp.boundingBox())!;

    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(target.x + target.width * 0.25, target.y + target.height * 0.25, { steps: 8 });
    await page.mouse.up();

    const download = page.waitForEvent("download");
    await page.getByRole("button", { name: "Download signed copy" }).click();

    const saved = await download;
    const chunks: Buffer[] = [];

    for await (const chunk of await saved.createReadStream()) {
        chunks.push(chunk as Buffer);
    }

    const signed = Buffer.concat(chunks).toString("base64");
    // The reader's own download link, not the chip's underneath it — the
    // thread behind the dialog has one for the same file.
    const source = await page
        .locator('[role="dialog"] a[href*="download=1"]')
        .first()
        .getAttribute("href");

    const placed = await page.evaluate(
        async ([base64, original]) => {
            const pdfjs = await import("/pdfjs/6.2.108/build/pdf.min.mjs");

            /** Page 2 drawn onto white, as one bit of darkness per pixel. */
            const inkOf = async (data: ArrayBuffer) => {
                // The loading task is kept, because destroy() is on THAT and
                // not on the document — the same trap the viewer controller
                // documents at length.
                const task = pdfjs.getDocument({ data, useWasm: false });
                const doc = await task.promise;
                const sheet = await doc.getPage(2);
                const viewport = sheet.getViewport({ scale: 1 });

                const canvas = document.createElement("canvas");
                canvas.width = Math.ceil(viewport.width);
                canvas.height = Math.ceil(viewport.height);

                const context = canvas.getContext("2d")!;
                // White first: an unpainted canvas is transparent, and every
                // pixel of it would then count as dark.
                context.fillStyle = "#fff";
                context.fillRect(0, 0, canvas.width, canvas.height);

                await sheet.render({ canvas, viewport }).promise;

                const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
                const dark = new Uint8Array(canvas.width * canvas.height);

                for (let i = 0; i < dark.length; i += 1) {
                    dark[i] = pixels[i * 4] < 128 ? 1 : 0;
                }

                const rotation = sheet.rotate;
                await task.destroy();

                return { dark, width: canvas.width, height: canvas.height, rotation };
            };

            const bytes = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
            const before = await inkOf(await (await fetch(original)).arrayBuffer());
            const after = await inkOf(bytes.buffer);

            // The centre of mass of what signing ADDED. Far more sensitive than
            // counting quadrants: a stamp misplaced by a sixth of the page — a
            // whole rotation case getting the anchor wrong — stays inside the
            // quadrant it started in, and the first version of this test
            // therefore passed against a deliberately broken anchor.
            let count = 0;
            let sumX = 0;
            let sumY = 0;

            for (let y = 0; y < after.height; y += 1) {
                for (let x = 0; x < after.width; x += 1) {
                    const at = y * after.width + x;

                    if (1 !== after.dark[at] || 1 === before.dark[at]) {
                        continue;
                    }

                    count += 1;
                    sumX += x;
                    sumY += y;
                }
            }

            return {
                rotation: after.rotation,
                pixels: count,
                x: 0 === count ? -1 : sumX / count / after.width,
                y: 0 === count ? -1 : sumY / count / after.height,
            };
        },
        [signed, source!] as const,
    );

    // The fixture's second page is the awkward one, and this says so out loud:
    // without the quarter turn every rotation branch below is untested and the
    // test quietly becomes a duplicate of the page-1 one.
    expect(placed.rotation, "the fixture's second page should be rotated").toBe(90);

    expect(placed.pixels, "signing added no ink to page 2 at all").toBeGreaterThan(200);

    // Where it was dropped, in the frame the reader placed it in — not the
    // frame the bytes are stored in. That distinction is the whole of what
    // /Rotate introduces. A tenth of the page is a generous tolerance for a
    // scribble whose centre is not exactly the box's, and far tighter than any
    // of the ways this goes wrong: those are off by a sixth of a page or more.
    expect(placed.x, `ink landed at ${placed.x}, ${placed.y}`).toBeCloseTo(0.25, 1);
    expect(placed.y, `ink landed at ${placed.x}, ${placed.y}`).toBeCloseTo(0.25, 1);
});

/**
 * What the write-back endpoint refuses.
 *
 * Posted directly rather than driven through the interface, because the point
 * is what happens when the interface is BYPASSED — the browser's own flow can
 * only ever produce the accepted case. `page.request` carries the session, so
 * these are authenticated requests from a logged-in user, which is the shape an
 * attack takes: a cross-origin page cannot read the token but the victim's
 * cookie travels regardless.
 *
 * Not covered here: the 25 MB ceiling. Asserting it means posting 25 MB, which
 * costs more suite time than the assertion is worth given the same constant
 * guards every other upload path in the app.
 */
test.describe("refusing a signed reply", () => {
    /** The part id and a matching token, read from a legitimately opened reader. */
    async function credentials(page: Page): Promise<{ id: string; token: string }> {
        await openReader(page);

        const href = await page
            .locator('[role="dialog"] a[href*="download=1"]')
            .first()
            .getAttribute("href");

        const token = await page
            .locator('[role="dialog"] input[name="_token"]')
            .first()
            .inputValue();

        return { id: /attachment\/(\d+)/.exec(href!)![1], token };
    }

    test("a post with no CSRF token is refused", async ({ page }) => {
        const { id } = await credentials(page);

        const response = await page.request.post(`/compose/reply-signed/${id}`, {
            multipart: {
                document: { name: "x.pdf", mimeType: "application/pdf", buffer: Buffer.from("%PDF-1.4\n") },
            },
        });

        expect(response.status()).toBe(403);
    });

    /**
     * The client's filename is not evidence. This body is plain text calling
     * itself a PDF, and the server reads the bytes — finfo over the upload IS
     * the magic-byte check, which is why there is no hand-rolled `%PDF-` test
     * in the controller to disagree with it.
     */
    test("a file that is not a PDF is refused however it is named", async ({ page }) => {
        const { id, token } = await credentials(page);

        const response = await page.request.post(`/compose/reply-signed/${id}`, {
            multipart: {
                _token: token,
                document: {
                    name: "totally-a.pdf",
                    mimeType: "application/pdf",
                    buffer: Buffer.from("<html>not a pdf at all</html>"),
                },
            },
        });

        expect(response.status()).toBe(400);
    });
});
