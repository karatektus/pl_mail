import { Controller } from "@hotwired/stimulus";
import { Idiomorph } from "idiomorph";

/**
 * Answering an invitation, seen from the calendar.
 *
 * Answering Yes or Maybe puts the meeting on the calendar (InviteResponder
 * writes its occurrences), and until this existed nothing on screen said so:
 * the card was redrawn with its answer, a toast appeared in the far corner, and
 * the calendar pane beside it — the one place the answer actually changed —
 * went on showing the week without it until the page was reloaded.
 *
 * So the calendar is now part of the answer, in three layers, each of which
 * works without the one after it:
 *
 *   1. **Preview.** Hovering or focusing Yes or Maybe outlines the slot the
 *      meeting would take, dashed, in its calendar's colour. It moves nothing,
 *      so it is there at every motion level: under reduced motion it is the
 *      whole of the feature, and it says where the meeting goes before the
 *      click rather than after it.
 *   2. **Flight.** At Full motion the pressed answer lifts off, arcs across to
 *      that slot and turns into the meeting on the way — or, with the calendar
 *      closed, shrinks into a dot on the calendar button in the top bar.
 *      --motion-travel decides, and it is zero below Full and under
 *      prefers-reduced-motion. See MotionLevel::travel().
 *   3. **Redraw.** Once the server has answered, the pane is fetched again and
 *      morphed in place — no reload, no lost scroll position — and the real
 *      block is lit where the placeholder stood.
 *
 * A Maybe is striped all the way through — outline, flight and placeholder —
 * because the grid draws a meeting answered Maybe striped ([data-tentative]).
 *
 * **Nothing waits for the server that does not have to.** Answering sends the
 * organiser an iTIP reply before the response comes back, and through Gmail or
 * Graph that can take a second or two. So the button takes its answer at once,
 * the slot is worked out here from the meeting's time and the grid's own clock,
 * and the flight lands in it immediately; what it leaves there is a placeholder
 * the real block replaces once it exists. A failed request takes all of that
 * back, and the card the server did not redraw keeps its old answer.
 *
 * **The flight outlives this controller.** The response replaces the card, and
 * with it this element and this instance, while the flyer may still be in the
 * air and the placeholder is still waiting. So an answer in progress lives in
 * the module (`current`) rather than on the instance, and only a hovered
 * preview — which really does belong to the card that drew it — goes when the
 * card does.
 *
 * Values:
 *   event    the CalendarEvent's id; the grid's chips carry it as data-event-chip
 *   starts   start instant, ISO 8601 in UTC
 *   ends     end instant, likewise
 *   allDay   whether the meeting has no time of day; the grid draws those in
 *            the all-day band, laid out in lanes only the server knows, so they
 *            have no slot to preview and are lit once drawn
 *   drawn    whether it is on the calendar already — answered Yes or Maybe
 *   placed   what a screen reader hears once it is in the calendar
 *
 * Targets:
 *   face     <template>: the meeting as the calendar draws it
 */

const PANE_FRAME_ID = "calendar-pane-frame";

/** What the pane loads when nothing has navigated it — see ui--split. */
const PANE_URL = "/calendar/pane";

/** The slot's id, which also keeps the pane's redraw from reusing it. */
const SLOT_ID = "invite-slot";

/** Minutes in a day: the time grid's vertical axis. */
const DAY = 1440;

/** How long the landing mark is held before it fades. */
const HOLD = 1100;

/** Longest an answer may go unanswered before what it drew is cleared up. */
const GIVE_UP = 30_000;

/** The answers that put a meeting on the calendar. */
const DRAWING = new Set(["accepted", "tentative"]);

/** Classes an answer button swaps between. Kept in step with _invite_card. */
const CHOSEN = ["bg-accent", "text-accent-ink"];
const UNCHOSEN = ["border", "border-line", "text-ink-muted", "hover:bg-hover", "hover:text-ink"];

/**
 * The answer in progress, or null. One at a time: answering again supersedes
 * whatever the last answer left on screen.
 */
let current = null;

export default class extends Controller {
    static targets = ["face"];

    static values = {
        event: Number,
        starts: String,
        ends: String,
        allDay: Boolean,
        drawn: Boolean,
        placed: String,
    };

    #preview = null;

    disconnect() {
        this.#dropPreview();
    }

    // ── 1. Preview ────────────────────────────────────────────────────────

    /** Hovering or focusing Yes or Maybe: outline the slot it would take. */
    preview(event) {
        if (this.drawnValue || null !== current || this.#preview?.isConnected) {
            return;
        }

        const frame = visiblePane();
        const tentative = "tentative" === answerOf(event.currentTarget.closest("form"));
        const slot = null === frame ? null : this.#placeSlot(frame, "preview", tentative);

        if (null === slot) {
            return;
        }

        this.#preview = slot;
        reveal(slot);
    }

    unpreview() {
        this.#dropPreview();
    }

    #dropPreview() {
        this.#preview?.remove();
        this.#preview = null;
    }

    // ── 2. Flight ─────────────────────────────────────────────────────────

    /**
     * An answer was submitted. Runs before Turbo takes the form — a listener
     * on the card sits below Turbo's on the document — so the pressed button is
     * still where the eye last saw it.
     */
    answer(event) {
        const form = event.target;
        const button = event.submitter ?? form.querySelector('button[type="submit"]');
        const answer = answerOf(form);

        settle();

        const record = begin(this.eventValue, DRAWING.has(answer), "tentative" === answer, this.placedValue);
        const restore = choose(this.element, button);

        // Turbo dispatches this on the form once the response is in and before
        // it renders the stream, so the form is still in the document to hear
        // it — the card it belongs to is about to be replaced.
        form.addEventListener("turbo:submit-end", (ended) => {
            if (current !== record) {
                return;
            }

            if (true !== ended.detail?.success) {
                restore();
                abandon(record);

                return;
            }

            confirm(record);
        }, { once: true });

        record.timers.push(setTimeout(() => current === record && abandon(record), GIVE_UP));

        const preview = this.#preview;
        this.#preview = null;

        // An answer that takes the meeting off the calendar, or one for a
        // meeting already on it, has nowhere to travel: the redraw is all of it.
        if (!record.lights || this.drawnValue || null === button) {
            preview?.remove();
            record.land();

            return;
        }

        const travels = travelMs() > 0;
        const from = rectOf(button.getBoundingClientRect());
        const answerHtml = button.innerHTML;
        const frame = visiblePane();

        if (null === frame) {
            preview?.remove();
            this.#toTheCalendarButton(record, from, answerHtml, travels);

            return;
        }

        const slot = preview?.isConnected ? preview : this.#placeSlot(frame, "pending", record.tentative);

        if (null === slot) {
            // Nowhere in this view to work a slot out — another week, a month,
            // an all-day row. The redraw will say where the meeting is.
            record.late = { from, answerHtml, face: this.faceTarget };
            record.land();

            return;
        }

        record.slot = slot;

        // A preview is of whichever answer was last pointed at; the slot is of
        // the one given.
        slot.firstElementChild?.toggleAttribute("data-tentative", record.tentative);

        if (!travels) {
            slot.dataset.inviteSlot = "pending";
            reveal(slot);
            record.land();

            return;
        }

        // Out of sight while it is measured and flown to; shown the moment the
        // flight arrives.
        slot.dataset.inviteSlot = "hidden";
        record.onLand = () => {
            slot.dataset.inviteSlot = "pending";
        };

        reveal(slot, () => {
            if (current === record) {
                fly(record, from, answerHtml, this.faceTarget, rectOf(slot.firstElementChild.getBoundingClientRect()), false);
            }
        });
    }

    /** With the calendar closed, the answer goes to the button that opens it. */
    #toTheCalendarButton(record, from, answerHtml, travels) {
        const toggle = visibleToggle();

        if (null === toggle) {
            record.land();

            return;
        }

        record.onLand = () => markToggle(record, toggle);

        if (travels) {
            fly(record, from, answerHtml, this.faceTarget, dotOn(toggle), true);

            return;
        }

        record.onLand();
        record.land();
    }

    /**
     * Where the meeting goes on the grid in the pane, drawn as `state` into
     * that day's block layer; null when this view has nowhere to put it.
     *
     * Positioned the way _time_grid_shell positions a block — a share of the
     * day down the column, as long as the meeting lasts, never shorter than a
     * block can be — on the grid's own clock, which is the one wall-clock
     * question here: the grid is drawn against its calendar's zone and the
     * card's instants are UTC. Full width, because the lanes overlapping
     * meetings are split into are the server's to work out; the real block
     * takes its lane a moment later.
     */
    #placeSlot(frame, state, tentative) {
        if (this.allDayValue) {
            return null;
        }

        const grid = frame.querySelector("[data-grid-zone]");

        if (null === grid) {
            return null;
        }

        const start = wallClock(this.startsValue, grid.dataset.gridZone);
        const end = wallClock(this.endsValue, grid.dataset.gridZone);

        if (null === start || null === end) {
            return null;
        }

        const layer = grid.querySelector(
            `[data-calendar--time-grid-target="column"][data-day="${start.day}"] [data-day-blocks]`,
        );

        if (null === layer) {
            return null;
        }

        const until = end.day === start.day ? end.minutes : DAY;

        document.getElementById(SLOT_ID)?.remove();

        const slot = document.createElement("div");

        slot.id = SLOT_ID;
        slot.dataset.inviteSlot = state;
        slot.setAttribute("aria-hidden", "true");
        slot.className = "absolute p-px";
        slot.style.top = `${(start.minutes * 100) / DAY}%`;
        slot.style.height = `${(Math.max(until - start.minutes, 0) * 100) / DAY}%`;
        slot.style.minHeight = "2.375rem";
        slot.style.left = "0";
        slot.style.width = "100%";
        slot.append(this.faceTarget.content.cloneNode(true));
        slot.firstElementChild?.toggleAttribute("data-tentative", tentative);
        layer.append(slot);

        return slot;
    }
}

// ── The answer in progress ────────────────────────────────────────────────

function begin(eventId, lights, tentative, placed) {
    const record = {
        eventId,
        lights,
        tentative,
        placed,
        slot: null,
        flyer: null,
        dot: null,
        late: null,
        onLand: null,
        landed: null,
        land: null,
        timers: [],
        animations: [],
    };

    record.landed = new Promise((resolve) => {
        record.land = resolve;
    });

    // Created up front, so it is already in the document when there is
    // something to say — a live region added and filled in one go is one some
    // screen readers never announce.
    announcer();

    current = record;

    return record;
}

/** Clear away whatever the last answer left: a new one supersedes it. */
function settle() {
    if (null !== current) {
        abandon(current);
    }
}

/** Take back everything an answer put on screen. */
function abandon(record) {
    record.timers.forEach(clearTimeout);
    record.animations.forEach((animation) => animation.cancel());
    record.flyer?.remove();
    record.slot?.remove();
    record.dot?.remove();
    record.land();

    if (current === record) {
        current = null;
    }
}

function finish(record) {
    record.timers.forEach(clearTimeout);

    if (current === record) {
        current = null;
    }
}

/**
 * The server has the answer: bring the pane up to date, and show the meeting
 * where it now is.
 */
async function confirm(record) {
    const frame = document.getElementById(PANE_FRAME_ID);
    const redrawn = null !== frame && hasContent(frame) && await redraw(frame);

    if (current !== record) {
        return;
    }

    if (!record.lights) {
        finish(record);

        return;
    }

    const chip = redrawn ? frame.querySelector(`[data-event-chip="${record.eventId}"]`) : null;
    const shown = null !== chip && isShown(chip);

    if (null !== record.late) {
        // The view had no slot before the redraw. It has a block now, or it
        // shows another range altogether — and then the heading saying which
        // range is on screen is where to look.
        const heading = shown ? null : visiblePane()?.querySelector("[data-calendar-position]") ?? null;
        const target = shown ? chip : heading;

        if (null !== target) {
            await flyLate(record, target);
        }

        if (null !== heading) {
            ring(heading);
        }
    } else {
        // A redraw faster than the flight: the real block waits, unseen, for
        // the placeholder it replaces to arrive.
        const piece = shown ? pieceOf(chip) : null;

        piece?.style.setProperty("visibility", "hidden");
        await record.landed;
        piece?.style.removeProperty("visibility");

        if (current !== record) {
            return;
        }
    }

    record.slot?.remove();
    record.slot = null;

    if (shown) {
        arrive(chip);
    } else if (null === visiblePane()) {
        lightOnOpen(record.eventId);
    }

    announce(record.placed);
    finish(record);
}

// ── The pane ──────────────────────────────────────────────────────────────

/** The calendar pane, if it is open and on screen. */
function visiblePane() {
    const frame = document.getElementById(PANE_FRAME_ID);

    return null !== frame && hasContent(frame) && isShown(frame) ? frame : null;
}

/** Whether the pane has ever loaded a view; a closed pane may never have. */
function hasContent(frame) {
    return null !== frame.querySelector("[data-pane-min-width]");
}

/**
 * Fetch the view the pane is showing and morph it in.
 *
 * By hand, the way mail--mail-pane refreshes the list, and for one of the same
 * reasons: a pane that was open at page load was rendered into the page and has
 * no `src`, so `frame.reload()` has nothing to re-fetch. A morph rather than a
 * swap keeps every block that did not change the node it was, so the grid keeps
 * its scroll position and nothing replays an entrance.
 *
 * The slot is spared: it is not in what the server sent, and a morph removes
 * what is not. The placeholder is taken away once the real block is lit.
 */
async function redraw(frame) {
    let response;

    try {
        response = await fetch(frame.getAttribute("src") || PANE_URL, {
            headers: { Accept: "text/html", "Turbo-Frame": PANE_FRAME_ID },
            credentials: "same-origin",
        });
    } catch {
        return false;
    }

    if (!response.ok) {
        return false;
    }

    const fresh = new DOMParser()
        .parseFromString(await response.text(), "text/html")
        .getElementById(PANE_FRAME_ID);

    if (null === fresh || !frame.isConnected) {
        return false;
    }

    Idiomorph.morph(frame, fresh.innerHTML, {
        morphStyle: "innerHTML",
        callbacks: {
            beforeNodeRemoved: (node) => !(node instanceof Element && SLOT_ID === node.id),
        },
    });

    return true;
}

/**
 * The piece of the grid a chip is drawn in: the positioned block on the time
 * grid, the bar in the all-day band, or — in the month and the agenda — the
 * chip itself. It is what carries `is-lit`, the grid's own word for "this one".
 */
function pieceOf(chip) {
    return chip.closest('[data-calendar--time-grid-target="block"], [data-span-key]') ?? chip;
}

/** The box a piece is drawn as, which the landing ring goes around. */
function boxOf(piece, chip) {
    return piece === chip ? chip : (piece.firstElementChild ?? piece);
}

/** Light a chip where it is, and leave the ring that fades. */
function arrive(chip) {
    const piece = pieceOf(chip);

    reveal(piece);
    ring(boxOf(piece, chip), piece);
}

/**
 * The landing mark: the accent ring on `box` and `is-lit` on `piece`, held a
 * moment and then faded over the length of a journey — which below Full is no
 * time at all, so there the mark simply goes.
 */
function ring(box, piece = null) {
    const positioned = "static" !== getComputedStyle(box).position;

    if (!positioned) {
        box.style.position = "relative";
    }

    piece?.classList.add("is-lit");
    box.dataset.arrived = "on";

    setTimeout(() => {
        box.dataset.arrived = "fading";
        piece?.classList.remove("is-lit");

        setTimeout(() => {
            delete box.dataset.arrived;

            if (!positioned) {
                box.style.removeProperty("position");
            }
        }, travelMs() + 50);
    }, HOLD);
}

/**
 * Bring an element into view in the pane, then carry on. Smoothly where things
 * may move, at once where they may not.
 *
 * On the time grid, both ways: below the pinned headings and the all-day band,
 * and — in a pane narrower than its week, which scrolls sideways — clear of
 * the pinned hour axis. Anywhere else, the nearest scroller does it.
 */
function reveal(element, then = null) {
    const scroller = element.closest('[data-calendar--time-grid-target="scroller"]');
    const hours = scroller?.querySelector('[data-calendar--time-grid-target="hours"]');

    if (!scroller || !hours) {
        element.scrollIntoView({ block: "nearest", inline: "nearest" });
        then?.();

        return;
    }

    const port = scroller.getBoundingClientRect();

    // The headings and the all-day band are sticky rows inside the scroller,
    // so what a block can be seen in starts below the lowest of them; the hour
    // axis is a sticky column, so it starts right of that too.
    let top = port.top;

    for (let row = hours.previousElementSibling; null !== row; row = row.previousElementSibling) {
        top = Math.max(top, row.getBoundingClientRect().bottom);
    }

    const left = Math.max(port.left, hours.firstElementChild?.getBoundingClientRect().right ?? port.left);
    const box = element.getBoundingClientRect();
    const fitsDown = box.top >= top + 8 && box.bottom <= port.bottom - 8;
    const fitsAcross = box.left >= left - 1 && box.right <= port.right + 1;

    if (fitsDown && fitsAcross) {
        then?.();

        return;
    }

    const from = { top: scroller.scrollTop, left: scroller.scrollLeft };
    const to = {
        top: fitsDown ? from.top : Math.max(0, from.top + (box.top - top) - (port.bottom - top) / 3),
        left: fitsAcross ? from.left : Math.max(0, from.left + (box.left - left) - (port.right - left - box.width) / 2),
    };
    const duration = milliseconds(token("--motion-slow"));

    // The grid snaps its columns into place, which would fight a scroll that
    // is written a frame at a time.
    scroller.style.scrollSnapType = "none";

    const done = () => {
        scroller.style.removeProperty("scroll-snap-type");
        then?.();
    };

    if (duration <= 0) {
        scroller.scrollTop = to.top;
        scroller.scrollLeft = to.left;
        done();

        return;
    }

    const ease = curve(token("--motion-ease"), [0.22, 0.68, 0.32, 1]);
    const started = performance.now();
    const step = (now) => {
        const progress = Math.min(1, (now - started) / duration);

        scroller.scrollTop = from.top + (to.top - from.top) * ease(progress);
        scroller.scrollLeft = from.left + (to.left - from.left) * ease(progress);

        if (progress < 1) {
            requestAnimationFrame(step);
        } else {
            done();
        }
    };

    requestAnimationFrame(step);
}

// ── The calendar button ───────────────────────────────────────────────────

function visibleToggle() {
    return [...document.querySelectorAll("[data-calendar-toggle]")].find(isShown) ?? null;
}

/** Where a flight to the calendar button lands: its corner dot. */
function dotOn(toggle) {
    const box = toggle.getBoundingClientRect();

    return { x: box.right - 14, y: box.top + 6, w: 8, h: 8 };
}

/**
 * Mark the calendar button: the ring, and a dot for as long as the calendar
 * stays closed — unless it already carries its own, the dot saying a meeting is
 * close, which matters more than this does.
 */
function markToggle(record, toggle) {
    ring(toggle);

    if (null !== toggle.querySelector(":scope > span.absolute.rounded-full")) {
        return;
    }

    const dot = document.createElement("span");

    dot.dataset.inviteDot = "";
    dot.className = "absolute top-1.5 right-1.5 w-2 h-2 rounded-full ring-2 ring-surface bg-accent";
    dot.setAttribute("aria-hidden", "true");
    toggle.append(dot);
    toggle.addEventListener("click", () => dot.remove(), { once: true });
    record.dot = dot;
}

/** Light the meeting the next time the calendar is opened. */
function lightOnOpen(eventId) {
    visibleToggle()?.addEventListener("click", () => {
        let tries = 0;

        // The pane may still have to load (it is lazy until first shown), so
        // look for a while rather than once.
        const look = () => {
            const chip = visiblePane()?.querySelector(`[data-event-chip="${eventId}"]`) ?? null;

            if (null !== chip && isShown(chip)) {
                arrive(chip);
            } else if (tries++ < 40) {
                setTimeout(look, 75);
            }
        };

        requestAnimationFrame(look);
    }, { once: true });
}

// ── The flight itself ─────────────────────────────────────────────────────

/**
 * Fly the answer from `from` to `to` (viewport boxes, {x, y, w, h}), turning it
 * into the meeting on the way — and, `toDot`, on into the dot it lands as.
 *
 * Positions are sampled along a quadratic arc bowed upwards, so it lifts before
 * it travels, and eased by --motion-travel-ease; the size and the faces follow
 * the same eased progress, the answer fading out and the meeting in across the
 * middle of the journey. Web Animations with linear timing, because the curve
 * is already in the samples.
 */
function fly(record, from, answerHtml, face, to, toDot) {
    const duration = travelMs();
    const ease = curve(token("--motion-travel-ease"), [0.32, 0, 0.18, 1]);
    const chip = toDot ? { w: 96, h: 38 } : { w: to.w, h: to.h };

    // The meeting, laid out once at its full size and uncovered as the box
    // grows round it, so its words never reflow on the way.
    const meeting = document.createElement("div");
    const pin = document.createElement("div");

    pin.style.cssText = `position:absolute;left:0;top:0;width:${chip.w}px;height:${chip.h}px`;
    pin.append(face.content.cloneNode(true));
    pin.firstElementChild?.toggleAttribute("data-tentative", record.tentative);
    meeting.append(pin);

    const answer = document.createElement("div");

    answer.className = "flex items-center justify-center gap-1.5 text-xs font-medium whitespace-nowrap bg-accent text-accent-ink";
    answer.innerHTML = answerHtml;

    const dot = document.createElement("div");

    dot.className = "rounded-full bg-accent";

    const flyer = document.createElement("div");

    flyer.className = "invite-flyer";
    flyer.setAttribute("aria-hidden", "true");
    flyer.append(meeting, answer, dot);

    const sx = from.x + from.w / 2;
    const sy = from.y + from.h / 2;
    const ex = to.x + to.w / 2;
    const ey = to.y + to.h / 2;
    const distance = Math.hypot(ex - sx, ey - sy) || 1;

    // The control point sits off the straight line, on its upper side.
    let nx = (ey - sy) / distance;
    let ny = -(ex - sx) / distance;

    if (ny > 0) {
        nx = -nx;
        ny = -ny;
    }

    const cx = (sx + ex) / 2 + nx * distance * 0.2;
    const cy = (sy + ey) / 2 + ny * distance * 0.2;

    const steps = 48;
    const frames = { box: [], answer: [], meeting: [], dot: [] };

    for (let i = 0; i <= steps; i++) {
        const t = i / steps;
        const u = ease(t);
        const x = (1 - u) * (1 - u) * sx + 2 * (1 - u) * u * cx + u * u * ex;
        const y = (1 - u) * (1 - u) * sy + 2 * (1 - u) * u * cy + u * u * ey;
        let w;
        let h;
        let radius;
        let meetingAlpha;
        let dotAlpha = 0;

        if (toDot) {
            const grow = smooth(0.15, 0.55, u);
            const shrink = smooth(0.68, 0.97, u);

            w = lerp(lerp(from.w, chip.w, grow), to.w, shrink);
            h = lerp(lerp(from.h, chip.h, grow), to.h, shrink);
            radius = lerp(lerp(8, 6, grow), to.w / 2, shrink);
            meetingAlpha = smooth(0.3, 0.52, u) * (1 - smooth(0.72, 0.9, u));
            dotAlpha = smooth(0.76, 0.93, u);
        } else {
            const morph = smooth(0.25, 0.85, u);

            w = lerp(from.w, to.w, morph);
            h = lerp(from.h, to.h, morph);
            radius = lerp(8, 6, morph);
            meetingAlpha = smooth(0.38, 0.68, u);
        }

        // Lifted in the middle of the journey, settled at both ends. Neutral:
        // a coloured shadow is one of the things DESIGN.md rules out.
        const lift = Math.sin(Math.PI * Math.min(1, t * 1.05));

        frames.box.push({
            offset: t,
            left: `${x - w / 2}px`,
            top: `${y - h / 2}px`,
            width: `${w}px`,
            height: `${h}px`,
            borderRadius: `${radius}px`,
            boxShadow: toDot && u > 0.9
                ? "none"
                : `0 ${(2 + 10 * lift).toFixed(1)}px ${(4 + 22 * lift).toFixed(1)}px -${(2 + 4 * lift).toFixed(1)}px rgb(0 0 0 / ${(0.06 + 0.16 * lift).toFixed(3)})`,
        });
        frames.answer.push({ offset: t, opacity: 1 - smooth(0.3, 0.6, u) });
        frames.meeting.push({ offset: t, opacity: meetingAlpha });
        frames.dot.push({ offset: t, opacity: dotAlpha });
    }

    Object.assign(flyer.style, {
        left: `${from.x}px`,
        top: `${from.y}px`,
        width: `${from.w}px`,
        height: `${from.h}px`,
        borderRadius: "8px",
    });
    document.body.append(flyer);
    record.flyer = flyer;

    const timing = { duration, easing: "linear", fill: "forwards" };
    const travel = flyer.animate(frames.box, timing);

    record.animations.push(
        travel,
        answer.animate(frames.answer, timing),
        meeting.animate(frames.meeting, timing),
        dot.animate(frames.dot, timing),
    );

    // Lands on its own finish or, should a hidden tab have held its frames
    // back, on the clock — once either way.
    let landed = false;
    const land = () => {
        if (landed || current !== record) {
            return;
        }

        landed = true;
        record.onLand?.();
        flyer.remove();
        record.flyer = null;
        record.animations = [];
        record.land();
    };

    travel.onfinish = land;
    record.timers.push(setTimeout(land, duration + 250));
}

/**
 * A flight that could only be decided once the pane was redrawn: from where the
 * answer was pressed to the block now drawn, or to the heading when the meeting
 * is outside the range on screen. Resolves once it has landed.
 */
function flyLate(record, target) {
    if (travelMs() <= 0) {
        return Promise.resolve();
    }

    const { from, answerHtml, face } = record.late;
    const isChip = target.hasAttribute("data-event-chip");
    const piece = isChip ? pieceOf(target) : null;

    return new Promise((resolve) => {
        reveal(piece ?? target, () => {
            if (current !== record) {
                resolve();

                return;
            }

            const box = target.getBoundingClientRect();
            const to = isChip
                ? rectOf(boxOf(piece, target).getBoundingClientRect())
                : { x: box.left + 4, y: box.top + box.height / 2 - 4, w: 8, h: 8 };

            piece?.style.setProperty("visibility", "hidden");
            record.onLand = () => piece?.style.removeProperty("visibility");
            record.land = resolve;
            fly(record, from, answerHtml, face, to, !isChip);
        });
    });
}

// ── The answer buttons ────────────────────────────────────────────────────

/** The answer a card's form sends: accepted, tentative or declined. */
function answerOf(form) {
    return form?.querySelector('input[name="status"]')?.value ?? "";
}

/**
 * Show `button` as the answer given and every other one as not — at once,
 * rather than when the redrawn card arrives. Returns what puts them back.
 */
function choose(card, button) {
    const buttons = [...card.querySelectorAll('form button[type="submit"]')];
    const before = buttons.map((b) => b.className);

    buttons.forEach((b) => {
        const chosen = b === button;

        b.classList.remove(...(chosen ? UNCHOSEN : CHOSEN));
        b.classList.add(...(chosen ? CHOSEN : UNCHOSEN));
    });

    return () => buttons.forEach((b, i) => {
        b.className = before[i];
    });
}

// ── Screen readers ────────────────────────────────────────────────────────

function announcer() {
    let region = document.getElementById("invite-announcer");

    if (null === region) {
        region = document.createElement("p");
        region.id = "invite-announcer";
        region.className = "sr-only";
        region.setAttribute("role", "status");
        region.setAttribute("aria-live", "polite");
        document.body.append(region);
    }

    return region;
}

function announce(text) {
    if (!text) {
        return;
    }

    const region = announcer();

    region.textContent = "";
    requestAnimationFrame(() => {
        region.textContent = text;
    });
}

// ── Arithmetic ────────────────────────────────────────────────────────────

/**
 * An instant as the wall clock of `zone` reads it: the day, and the minutes
 * since its midnight. The grid places blocks on its calendar's clock, not the
 * browser's — see time_grid_controller's note on wall clocks — and this is the
 * one conversion that question needs.
 */
function wallClock(iso, zone) {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    let parts;

    try {
        parts = new Intl.DateTimeFormat("en-CA", {
            timeZone: zone,
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
            hourCycle: "h23",
        }).formatToParts(date);
    } catch {
        return null;
    }

    const part = (type) => parts.find((p) => p.type === type)?.value ?? "";

    return {
        day: `${part("year")}-${part("month")}-${part("day")}`,
        minutes: Number(part("hour")) * 60 + Number(part("minute")),
    };
}

function rectOf(box) {
    return { x: box.left, y: box.top, w: box.width, h: box.height };
}

function isShown(element) {
    const box = element.getBoundingClientRect();

    return box.width > 0 && box.height > 0;
}

function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

/** Milliseconds from a CSS duration, written in `ms` or `s`. */
function milliseconds(value) {
    const number = Number.parseFloat(value);

    if (Number.isNaN(number)) {
        return 0;
    }

    return value.endsWith("ms") ? number : number * 1000;
}

/**
 * How long the flight takes — zero wherever things may not travel. The token is
 * already zero under reduced motion; asking the media query as well covers a
 * page whose stylesheet has not arrived.
 */
function travelMs() {
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        return 0;
    }

    return milliseconds(token("--motion-travel"));
}

/** A `cubic-bezier()` token, as a function of progress. */
function curve(value, fallback) {
    const match = value.match(/cubic-bezier\(([^)]+)\)/);
    const numbers = match ? match[1].split(",").map(Number) : [];
    const [p1x, p1y, p2x, p2y] = 4 === numbers.length && numbers.every(Number.isFinite) ? numbers : fallback;

    const cx = 3 * p1x;
    const bx = 3 * (p2x - p1x) - cx;
    const ax = 1 - cx - bx;
    const cy = 3 * p1y;
    const by = 3 * (p2y - p1y) - cy;
    const ay = 1 - cy - by;
    const x = (t) => ((ax * t + bx) * t + cx) * t;
    const y = (t) => ((ay * t + by) * t + cy) * t;

    return (progress) => {
        if (progress <= 0) return 0;
        if (progress >= 1) return 1;

        let low = 0;
        let high = 1;
        let t = progress;

        for (let i = 0; i < 40; i++) {
            const value = x(t);

            if (Math.abs(value - progress) < 1e-6) break;

            if (value < progress) {
                low = t;
            } else {
                high = t;
            }

            t = (low + high) / 2;
        }

        return y(t);
    };
}

function smooth(from, to, value) {
    const t = Math.min(1, Math.max(0, (value - from) / (to - from)));

    return t * t * (3 - 2 * t);
}

function lerp(from, to, t) {
    return from + (to - from) * t;
}
