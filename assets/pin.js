/**
 * Pinning a conversation (#654), as the row, the open conversation's toolbar
 * and the selection toolbar all do it: one request, the "pin anyway?" reminder
 * when the server asks for it, and the row moved into its place without the
 * rest of the list moving.
 *
 * The move is the part worth a module. Pinning is meant to be done while
 * sifting through a list, and reloading the list to show the new order would
 * throw the reader back to the top of it. So it happens here, in three beats:
 * the thumbtack swings down and stabs the row (swingTack), the row is redrawn
 * by the server's stream where it stands, and then it glides up to the pinned
 * group (placeRow).
 *
 * The rows below the one that left never move: one row left above them and
 * one arrived above them, so they are where they were, and the scroll is held
 * still so they stay there. The rows between the top and the old slot are the
 * only ones that must make room, and they slide down one row rather than jump.
 * Keeping THEM still instead would push the pinned row out of view above the
 * top of the list whenever the list is scrolled to the top, which is where
 * most pinning happens and where the row has to be seen arriving.
 *
 * Every duration is scaled by the motion setting (--motion-slow, from
 * MotionLevel), so "none" is no animation at all and reduced motion is less.
 */
import * as Turbo from "@hotwired/turbo";
import { jsonCsrfHeaders } from "./csrf.js";
import { listView } from "./list_view.js";
import { askConfirm } from "./confirm.js";
import { requestFailed } from "./request_errors.js";

/**
 * Post a pin or unpin, asking "pin anyway?" first if the server says the
 * reader is past their reminder.
 *
 * The server answers 409 with the question rather than pinning; a yes posts
 * again with `confirmed`, a no ends here having changed nothing. The reminder
 * nags and never refuses — the server pins whatever is confirmed.
 *
 * @param {string} url
 * @param {object} [body]
 * @returns {Promise<Response|null>} the answer to a pin that happened, or null
 */
export async function postPin(url, body = {}) {
    let response = await send(url, body);

    if (null !== response && 409 === response.status) {
        const question = await response.json();
        const confirmed = await askConfirm(question.reminder, {
            confirm: question.confirm,
            link: { href: question.settingsUrl, text: question.settings },
        });

        if (false === confirmed) {
            return null;
        }

        response = await send(url, { ...body, confirmed: true });
    }

    if (requestFailed(response)) {
        return null;
    }

    return response;
}

async function send(url, body) {
    try {
        return await fetch(url, {
            method: "POST",
            headers: jsonCsrfHeaders(),
            body: JSON.stringify({ ...listView(), ...body }),
        });
    } catch {
        return null;
    }
}

/**
 * Mark a list row pinned or not, and move it to where the list would draw it:
 * into the pinned group at the top, or back among the others.
 *
 * Within either group a row goes by its data-sort-at, in whichever direction
 * the list is sorted, read off the rows already there. An unpinned row older
 * than everything on the page goes to the end of the page; where it belongs
 * further down is the next page's business, and the next refresh puts it
 * there.
 *
 * @param {Element|null} row   the <li id="thread_…">
 * @param {boolean}      pinned
 * @returns {Promise<void>} when the row has arrived
 */
export function placeRow(row, pinned) {
    const list = row?.parentElement;

    if (!row || !list) {
        return Promise.resolve();
    }

    row.dataset.pinned = pinned ? "true" : "false";

    const others = [...list.children].filter((el) => el !== row && el.id.startsWith("thread_"));
    const group = others.filter((el) => ("true" === el.dataset.pinned) === pinned);
    const newestFirst = isNewestFirst(others);
    const key = Number(row.dataset.sortAt ?? 0);

    let before = group.find((el) => {
        const other = Number(el.dataset.sortAt ?? 0);

        return newestFirst ? other < key : other > key;
    }) ?? null;

    if (null === before) {
        // After the last row of its group: for a pin, that is in front of the
        // first unpinned row; for the rest, the end of the page.
        before = pinned ? (others.find((el) => "true" !== el.dataset.pinned) ?? null) : null;
    }

    if (before === row.nextElementSibling && null !== before) {
        return Promise.resolve();
    }

    return flip(list, row, () => list.insertBefore(row, before));
}

/**
 * Redraw a row from the server's answer, then move it into place.
 *
 * The stream replaces the row's node, so the move has to wait for the new one:
 * a row moved before the replace lands would be swapped out mid-glide, and the
 * animation with it. Turbo applies a stream on a later frame, so this waits a
 * few frames for the node under that id to change, and moves whatever is there
 * after that — the old node, if the answer had no row in it.
 *
 * @param {string}  id     the row's DOM id, `thread_…`
 * @param {string}  html   the turbo-stream answer
 * @param {boolean} pinned
 */
export async function redrawAndPlace(id, html, pinned) {
    const old = document.getElementById(id);

    if ("" !== html.trim()) {
        Turbo.renderStreamMessage(html);

        for (let frame = 0; frame < 20 && document.getElementById(id) === old; frame++) {
            await new Promise((resolve) => requestAnimationFrame(resolve));
        }
    }

    await placeRow(document.getElementById(id), pinned);
}

/**
 * The thumbtack's part: pinning swings it from its slant to straight down,
 * past it a little, and stabs — a short push into the row and back. Unpinning
 * only tilts it back. The end state is the class the server draws (rotate-45
 * and pin-outline for an unpinned tack, neither for a pinned one), so the
 * redrawn row that replaces this one looks exactly like the last frame.
 *
 * Tailwind's rotate-45 is the `rotate` property, not `transform`, so it is
 * taken off before a pin animates from 45° by transform, and put on after an
 * unpin has animated to it.
 *
 * @param {Element|null} icon    the <i> of the thumbtack
 * @param {boolean}      pinned  which way it went
 */
export async function swingTack(icon, pinned) {
    if (null === icon) {
        return;
    }

    const scale = motionScale();

    if (true === pinned) {
        icon.classList.remove("rotate-45", "pin-outline");
        icon.classList.add("text-accent");

        if (0 === scale) {
            return;
        }

        await icon.animate([
            { transform: "rotate(45deg)" },
            { transform: "rotate(-12deg)", offset: 0.4 },
            { transform: "rotate(0deg) translateY(-3px)", offset: 0.6 },
            { transform: "rotate(0deg) translateY(2px) scaleY(0.82)", offset: 0.78 },
            { transform: "none" },
        ], { duration: 560 * scale, easing: "cubic-bezier(0.3, 0.7, 0.4, 1)" }).finished.catch(() => {});

        return;
    }

    icon.classList.remove("text-accent");
    icon.classList.add("pin-outline");

    if (0 !== scale) {
        await icon.animate([
            { transform: "none" },
            { transform: "rotate(45deg)" },
        ], { duration: 220 * scale, easing: "ease-out" }).finished.catch(() => {});
    }

    icon.classList.add("rotate-45");
}

/**
 * Which way the list runs, from the first two rows whose times differ.
 * Newest first when nothing says otherwise — the default sort, and the only
 * answer for a page with a single date on it.
 *
 * @param {Element[]} rows
 */
function isNewestFirst(rows) {
    for (let i = 1; i < rows.length; i++) {
        const a = Number(rows[i - 1].dataset.sortAt ?? 0);
        const b = Number(rows[i].dataset.sortAt ?? 0);

        if ("true" !== rows[i - 1].dataset.pinned && "true" !== rows[i].dataset.pinned && a !== b) {
            return a > b;
        }
    }

    return true;
}

/**
 * Run `move`, then animate every row from where it was to where it is now —
 * the moved row gliding over the others, the rows it passed sliding to make
 * room. Nothing else on screen changes position: the browser's own scroll
 * anchoring is off for the move, because it would pick a row to keep still and
 * scroll the list under all the others to do it.
 *
 * @returns {Promise<void>} when the moved row has arrived
 */
function flip(list, moving, move) {
    const rows = [...list.children].filter((el) => el.id.startsWith("thread_"));
    const first = new Map(rows.map((el) => [el, el.getBoundingClientRect().top]));
    const scroller = scrollerOf(list);
    const anchoring = scroller.style.overflowAnchor;

    scroller.style.overflowAnchor = "none";
    move();
    requestAnimationFrame(() => {
        scroller.style.overflowAnchor = anchoring;
    });

    const scale = motionScale();

    if (0 === scale) {
        return Promise.resolve();
    }

    let arrived = Promise.resolve();

    for (const el of rows) {
        const dy = first.get(el) - el.getBoundingClientRect().top;

        if (0 === dy) {
            continue;
        }

        if (el === moving) {
            // Above the rows it passes, and lifted a little while it travels.
            el.style.zIndex = "20";

            const travel = el.animate([
                { transform: `translateY(${dy}px)`, boxShadow: "0 0 0 rgb(0 0 0 / 0)" },
                { transform: `translateY(${dy * 0.15}px)`, boxShadow: "0 8px 24px rgb(0 0 0 / 0.12)", offset: 0.7 },
                { transform: "none", boxShadow: "0 0 0 rgb(0 0 0 / 0)" },
            ], { duration: Math.min(700, 360 + Math.abs(dy) * 0.4) * scale, easing: "cubic-bezier(0.22, 0.68, 0.32, 1)" });

            arrived = travel.finished.catch(() => {}).then(() => {
                el.style.zIndex = "";
            });

            continue;
        }

        el.animate(
            [{ transform: `translateY(${dy}px)` }, { transform: "none" }],
            { duration: 320 * scale, easing: "cubic-bezier(0.22, 0.68, 0.32, 1)" },
        );
    }

    return arrived;
}

/**
 * How far to scale an animation: 1 at the standard motion level, 0 at none,
 * read off --motion-slow (260ms at the standard level), which the server sets
 * from the reader's motion setting and the system's reduced-motion request.
 */
function motionScale() {
    const value = getComputedStyle(document.documentElement).getPropertyValue("--motion-slow").trim();
    const number = parseFloat(value);

    if (false === Number.isFinite(number) || number <= 0) {
        return 0;
    }

    return (value.endsWith("ms") ? number : number * 1000) / 260;
}

function scrollerOf(element) {
    for (let el = element.parentElement; null !== el; el = el.parentElement) {
        const overflow = getComputedStyle(el).overflowY;

        if (("auto" === overflow || "scroll" === overflow) && el.scrollHeight > el.clientHeight) {
            return el;
        }
    }

    return document.scrollingElement;
}
