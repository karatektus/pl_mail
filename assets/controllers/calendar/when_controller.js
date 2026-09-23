import { Controller } from "@hotwired/stimulus";
import { prefersHour12 } from "../../clock_format.js";

/**
 * The event editor's start-and-end picker.
 *
 * Two cards and one panel: the cards say when the event starts and ends, the
 * panel sets whichever card is chosen — a strip of six days (a month behind the
 * calendar button), then the hour, then the minute (every five minutes and an
 * exact field behind the "···" button). Picking the start's minute moves on to
 * the end, which is the order people fill a meeting in.
 *
 * THE HIDDEN INPUTS ARE THE TRUTH
 * ───────────────────────────────
 * `start` and `end` are the form's own `startsAt` / `endsAt`, in the ISO wall
 * time they always carried ("2026-09-25T15:00"), and calendar--event-form still
 * owns them: it validates them, marks them invalid and — on a change to the
 * start — drags the end along to keep the event's length. So this controller
 * only ever WRITES them (and fires `input` and `change`, as a person typing
 * would) and REDRAWS from them. The end's `value` setter is wrapped on this
 * instance so a write from anywhere else — shiftEnd, above all — redraws too.
 *
 * THE END CANNOT BE BEFORE THE START
 * ──────────────────────────────────
 * Rather than being refused afterwards, the choices that would put it there are
 * disabled: days before the start, and on the start's own day the hours and
 * minutes at or before it. The server still refuses one, and the form still
 * says so, for a value that arrives some other way.
 *
 * Times are handled as minutes from `today`'s midnight, so a day is a multiple
 * of 1440 and "after the start" is one comparison. Dates are counted in UTC
 * days, never through a local Date's hours, so a DST change cannot make a day
 * 23 hours long.
 */
const DAY = 1440;

export default class extends Controller {
    static targets = ["start", "end", "startCard", "endCard", "panel"];
    static values = { today: String, labels: Object };

    connect() {
        this.hour12 = prefersHour12()
            ?? true === new Intl.DateTimeFormat(undefined, { hour: "numeric" }).resolvedOptions().hour12;
        this.lang = this.language();
        this.side = "start";
        this.month = false;
        this.fine = false;

        this.native = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value");
        for (const input of [this.startTarget, this.endTarget]) {
            const self = this;
            Object.defineProperty(input, "value", {
                configurable: true,
                get() { return self.native.get.call(this); },
                set(v) { self.native.set.call(this, v); self.render(); },
            });
            // The form focuses the first field marked invalid; a hidden input
            // cannot take focus, its card can.
            input.focus = () => (input === this.startTarget ? this.startCardTarget : this.endCardTarget).focus();
        }

        this.observer = new MutationObserver(() => this.render());
        this.observer.observe(this.startTarget, { attributes: true, attributeFilter: ["aria-invalid"] });
        this.observer.observe(this.endTarget, { attributes: true, attributeFilter: ["aria-invalid"] });

        this.allDayBox = this.element.closest("form")?.querySelector('input[name="isAllDay"]') ?? null;
        this.onAllDay = () => this.render();
        this.allDayBox?.addEventListener("change", this.onAllDay);

        this.onClick = (event) => this.pick(event);
        this.panelTarget.addEventListener("click", this.onClick);
        this.onExact = (event) => this.exact(event);
        this.panelTarget.addEventListener("change", this.onExact);

        this.render();
    }

    disconnect() {
        this.observer?.disconnect();
        this.allDayBox?.removeEventListener("change", this.onAllDay);
        this.panelTarget.removeEventListener("click", this.onClick);
        this.panelTarget.removeEventListener("change", this.onExact);
        for (const input of [this.startTarget, this.endTarget]) {
            delete input.value;
            delete input.focus;
        }
    }

    editStart() {
        this.side = "start";
        this.month = false;
        this.render();
    }

    editEnd() {
        this.side = "end";
        this.month = false;
        this.render();
    }

    // ── Reading and writing the inputs ───────────────────────────────────────

    read(input) {
        const m = String(this.native.get.call(input)).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
        if (!m) {
            return null;
        }
        return this.dayOf(+m[1], +m[2], +m[3]) * DAY + (+m[4]) * 60 + (+m[5]);
    }

    write(input, at) {
        const d = this.dateOf(Math.floor(at / DAY));
        const minutes = ((at % DAY) + DAY) % DAY;
        const pad = (n) => String(n).padStart(2, "0");
        const value = `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`
            + `T${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}`;

        if (value === this.native.get.call(input)) {
            return;
        }
        this.native.set.call(input, value);
        input.dispatchEvent(new Event("input", { bubbles: true }));
        input.dispatchEvent(new Event("change", { bubbles: true }));
        this.render();
    }

    /** The start moves and the end follows (calendar--event-form#shiftEnd). */
    setStart(at) {
        this.write(this.startTarget, at);
    }

    setEnd(at) {
        const start = this.read(this.startTarget);
        if (null === start || at > start) {
            this.write(this.endTarget, at);
        }
    }

    // ── The panel's buttons ──────────────────────────────────────────────────

    pick(event) {
        const button = event.target.closest("button[data-when]");
        if (!button || button.disabled) {
            return;
        }
        const { when, value } = button.dataset;
        const editingEnd = "end" === this.side;
        const start = this.read(this.startTarget);
        const at = this.read(editingEnd ? this.endTarget : this.startTarget) ?? 0;
        const day = Math.floor(at / DAY);
        const hh = Math.floor((at % DAY) / 60);
        const mm = at % 60;
        const put = (v) => (editingEnd ? this.setEnd(v) : this.setStart(v));
        const n = Number(value);

        this.focusKey = button.dataset.key;

        switch (when) {
            case "day": {
                let next = n * DAY + (at % DAY);
                // The end moved onto the start's own day, at a time before it:
                // an hour after the start, rather than a click that does nothing.
                if (editingEnd && null !== start && next <= start) {
                    next = start + 60;
                }
                put(next);
                this.month = false;
                break;
            }
            case "month":
                this.month = !this.month;
                break;
            case "hour": {
                let next = day * DAY + n * 60 + mm;
                // An end whose minute would put it at or before the start is
                // lifted to a quarter past the start, within the hour chosen.
                if (editingEnd && null !== start && next <= start) {
                    next = Math.min(start + 15, day * DAY + n * 60 + 59);
                }
                put(next);
                break;
            }
            case "half":
                if ((hh >= 12) !== (1 === n)) {
                    let next = day * DAY + (hh + (1 === n ? 12 : -12)) * 60 + mm;
                    if (editingEnd && null !== start && next <= start) {
                        next = start + 15;
                    }
                    put(next);
                }
                break;
            case "minute":
                put(day * DAY + hh * 60 + n);
                // From the quarter-hour row the start is done: on to the end, and
                // the keyboard with it. From the fine grid the reader is still
                // adjusting, so it stays.
                if (!editingEnd && !this.fine && mm % 15 === 0) {
                    this.side = "end";
                    this.focusKey = null;
                    this.render();
                    this.endCardTarget.focus();
                    return;
                }
                break;
            case "fine":
                this.fine = 1 === n;
                break;
            case "length":
                if (null !== start) {
                    this.write(this.endTarget, start + n);
                }
                break;
        }

        this.render();
    }

    exact(event) {
        if (!event.target.matches("input[data-when='exact']")) {
            return;
        }
        const v = parseInt(event.target.value, 10);
        if (!(v >= 0 && v <= 59)) {
            return;
        }
        const editingEnd = "end" === this.side;
        const at = this.read(editingEnd ? this.endTarget : this.startTarget) ?? 0;
        const base = Math.floor(at / DAY) * DAY + Math.floor((at % DAY) / 60) * 60;
        this.focusKey = "exact";
        editingEnd ? this.setEnd(base + v) : this.setStart(base + v);
    }

    // ── Drawing ──────────────────────────────────────────────────────────────

    render() {
        if (!this.hasPanelTarget) {
            return;
        }
        const L = this.labelsValue;
        const start = this.read(this.startTarget);
        const end = this.read(this.endTarget);
        const allDay = true === this.allDayBox?.checked;
        const editingEnd = "end" === this.side;

        this.card(this.startCardTarget, !editingEnd, this.startTarget, start, null, allDay);
        this.card(this.endCardTarget, editingEnd, this.endTarget, end, start, allDay);

        if (null === start || null === end) {
            this.panelTarget.replaceChildren();
            return;
        }

        const at = editingEnd ? end : start;
        const day = Math.floor(at / DAY);
        const hh = Math.floor((at % DAY) / 60);
        const mm = at % 60;
        const startDay = Math.floor(start / DAY);
        const parts = [];

        if (editingEnd && !allDay) {
            const len = end - start;
            parts.push(`<div class="flex flex-wrap gap-1.5" role="group" aria-label="${esc(L.length)}">`
                + [30, 60, 90, 120, 180].map((l) => this.chip("length", l, this.duration(l), l === len)).join("")
                + "</div>");
        }

        if (this.month) {
            parts.push(this.monthGrid(day, editingEnd ? startDay : null));
        } else {
            // Six days from today, or around the chosen day when it is further out.
            const anchor = day > 5 || day < 0 ? day - 2 : 0;
            let strip = "";
            for (let n = anchor; n < anchor + 6; n++) {
                const off = editingEnd && n < startDay;
                // "Today" does not fit a phone's seventh of the width; there it is
                // the weekday like the rest, marked by the ring instead.
                const label = editingEnd && n === startDay ? esc(L.sameShort)
                    : 0 === n ? `<span class="max-sm:hidden">${esc(L.today)}</span><span class="sm:hidden">${esc(this.weekday(n))}</span>`
                    : esc(this.weekday(n));
                const todayRing = 0 === n && n !== day ? "ring-1 ring-accent/40" : "";
                strip += `<button type="button" data-when="day" data-value="${n}" data-key="day-${n}"
                    aria-pressed="${n === day}" ${off ? "disabled" : ""}
                    aria-label="${esc(this.dayLabel(n))}"
                    class="flex-1 min-w-0 h-12 flex flex-col items-center justify-center gap-0.5 rounded-xl border text-ink
                           ${this.tone(n === day, off)} ${todayRing}">
                    <span class="text-[11px] truncate max-w-full px-0.5">${label}</span>
                    <span class="text-[15px] font-semibold tabular-nums">${this.dateOf(n).getUTCDate()}</span>
                </button>`;
            }
            strip += `<button type="button" data-when="month" data-key="month" aria-label="${esc(L.anotherDay)}"
                class="w-11 shrink-0 h-12 flex items-center justify-center rounded-xl border border-dashed border-line
                       text-ink-muted hover:bg-hover transition-colors cursor-pointer">
                <i class="fa-regular fa-calendar" aria-hidden="true"></i>
            </button>`;
            parts.push(`<div class="flex gap-1.5">${strip}</div>`);
        }

        if (!allDay) {
            parts.push(this.hourGrid(day, hh, editingEnd ? start : null));
            parts.push(this.minuteRow(day, hh, mm, editingEnd ? start : null));
        }

        this.panelTarget.innerHTML = parts.join("");

        if (this.focusKey) {
            this.panelTarget.querySelector(`[data-key="${this.focusKey}"]`)?.focus();
            this.focusKey = null;
        }
    }

    card(card, on, input, at, start, allDay) {
        const invalid = "true" === input.getAttribute("aria-invalid");
        card.setAttribute("aria-pressed", on ? "true" : "false");
        card.classList.toggle("border-accent", on && !invalid);
        card.classList.toggle("ring-2", on || invalid);
        card.classList.toggle("ring-accent/20", on && !invalid);
        card.classList.toggle("bg-surface", on);
        card.classList.toggle("bg-sunken", !on);
        card.classList.toggle("border-line", !on && !invalid);
        card.classList.toggle("border-danger", invalid);
        card.classList.toggle("ring-danger/30", invalid);

        const dayPart = card.querySelector('[data-when-part="day"]');
        const timePart = card.querySelector('[data-when-part="time"]');
        const lengthPart = card.querySelector('[data-when-part="length"]');

        if (null === at) {
            dayPart.textContent = "";
            timePart.textContent = "—";
            return;
        }

        const day = Math.floor(at / DAY);
        dayPart.textContent = null !== start && day === Math.floor(start / DAY)
            ? this.labelsValue.sameDay
            : this.dayLabel(day);
        timePart.textContent = allDay ? "" : this.time(at);
        timePart.hidden = allDay;

        if (lengthPart) {
            lengthPart.textContent = null !== start && !allDay && at > start ? this.duration(at - start) : "";
        }
    }

    hourGrid(day, hh, start) {
        const off = (h) => null !== start && day * DAY + h * 60 + 59 <= start;
        const cell = (h, label) => `<button type="button" data-when="hour" data-value="${h}" data-key="hour-${h}"
            aria-pressed="${h === hh}" ${off(h) ? "disabled" : ""}
            class="h-9 rounded-lg border text-sm tabular-nums ${this.tone(h === hh, off(h))}">${label}</button>`;

        if (!this.hour12) {
            let cells = "";
            for (let h = 0; h < 24; h++) {
                cells += cell(h, String(h).padStart(2, "0"));
            }
            return `<div role="group" aria-label="${esc(this.labelsValue.hour)}" class="grid grid-cols-6 gap-1">${cells}</div>`;
        }

        // 12, 1 … 11 in the half of the day that is chosen; am/pm picks the half.
        const pm = hh >= 12;
        let cells = "";
        for (let i = 0; i < 12; i++) {
            cells += cell(i + (pm ? 12 : 0), String(0 === i ? 12 : i));
        }
        const amOff = null !== start && day * DAY + 11 * 60 + 59 <= start;
        const half = (isPm, label) => {
            const sel = pm === isPm, disabled = !isPm && amOff;
            return `<button type="button" data-when="half" data-value="${isPm ? 1 : 0}" data-key="half-${isPm ? 1 : 0}"
                aria-pressed="${sel}" ${disabled ? "disabled" : ""}
                class="h-9 rounded-lg border text-sm ${this.tone(sel, disabled)}">${label}</button>`;
        };
        return `<div class="flex gap-2">
            <div role="group" aria-label="${esc(this.labelsValue.hour)}" class="flex-1 grid grid-cols-6 gap-1">${cells}</div>
            <div role="group" aria-label="${esc(this.labelsValue.half)}" class="w-14 shrink-0 flex flex-col gap-1">
                ${half(false, "am")}${half(true, "pm")}
            </div>
        </div>`;
    }

    minuteRow(day, hh, mm, start) {
        const L = this.labelsValue;
        const button = (m) => {
            const off = null !== start && day * DAY + hh * 60 + m <= start;
            return `<button type="button" data-when="minute" data-value="${m}" data-key="minute-${m}"
                aria-pressed="${m === mm}" ${off ? "disabled" : ""}
                class="h-9 rounded-lg border text-sm tabular-nums ${this.tone(m === mm, off)}">:${String(m).padStart(2, "0")}</button>`;
        };

        // Open by itself when the minute already sits between the quarters, so
        // the minute that is set is always one the reader can see.
        if (!this.fine && mm % 15 === 0) {
            return `<div role="group" aria-label="${esc(L.minute)}" class="flex gap-1">
                <div class="flex-1 grid grid-cols-4 gap-1">${[0, 15, 30, 45].map(button).join("")}</div>
                <button type="button" data-when="fine" data-value="1" data-key="fine-open" aria-label="${esc(L.anotherMinute)}"
                    class="w-11 shrink-0 h-9 rounded-lg border border-dashed border-line text-ink-muted tracking-widest
                           hover:bg-hover transition-colors cursor-pointer">···</button>
            </div>`;
        }

        let fine = "";
        for (let m = 0; m < 60; m += 5) {
            fine += button(m);
        }
        const id = `${this.startTarget.id}-exact-minute`;
        return `<div role="group" aria-label="${esc(L.minute)}" class="flex flex-col gap-2">
            <div class="grid grid-cols-6 gap-1">${fine}</div>
            <div class="flex items-center gap-2">
                <label for="${id}" class="text-xs text-ink-muted">${esc(L.exactMinute)}</label>
                <input id="${id}" type="number" min="0" max="59" inputmode="numeric" value="${mm}"
                       data-when="exact" data-key="exact"
                       class="w-16 h-8 rounded-lg border border-line bg-surface px-2.5 text-sm text-ink tabular-nums
                              focus:outline-none focus:ring-2 focus:ring-accent">
                <span class="flex-1"></span>
                <button type="button" data-when="fine" data-value="0" data-key="fine-close"
                    class="h-7 px-2.5 rounded-lg text-xs text-ink-muted hover:bg-hover transition-colors cursor-pointer">${esc(L.quarterHours)}</button>
            </div>
        </div>`;
    }

    monthGrid(day, startDay) {
        const view = this.dateOf(day);
        const firstDay = this.dayOf(view.getUTCFullYear(), view.getUTCMonth() + 1, 1);
        const firstWeekday = (this.dateOf(firstDay).getUTCDay() + 6) % 7;
        const gridStart = firstDay - firstWeekday;
        const title = this.format(view, { month: "long", year: "numeric" });

        let heads = "";
        for (let i = 0; i < 7; i++) {
            heads += `<span>${esc(this.format(this.dateOf(gridStart + i), { weekday: "narrow" }))}</span>`;
        }
        let cells = "";
        for (let i = 0; i < 42; i++) {
            const n = gridStart + i;
            const d = this.dateOf(n);
            const sel = n === day, off = null !== startDay && n < startDay;
            const inMonth = d.getUTCMonth() === view.getUTCMonth();
            cells += `<button type="button" data-when="day" data-value="${n}" data-key="mday-${n}"
                aria-pressed="${sel}" ${off ? "disabled" : ""} aria-label="${esc(this.dayLabel(n))}"
                class="h-8 rounded-full text-[13px] tabular-nums transition-colors
                       ${sel ? "bg-accent text-accent-ink font-semibold"
                           : off ? "text-ink-faint/40 cursor-default"
                           : `${0 === n ? "bg-accent/15 font-semibold" : ""} ${inMonth ? "text-ink" : "text-ink-faint"} hover:bg-hover cursor-pointer`}">${d.getUTCDate()}</button>`;
        }
        return `<div>
            <div class="flex items-center justify-between mb-1.5">
                <span class="text-sm font-semibold text-ink">${esc(title)}</span>
                <button type="button" data-when="month" data-key="month-close"
                    class="h-7 px-2.5 rounded-lg text-xs text-ink-muted hover:bg-hover transition-colors cursor-pointer">${esc(this.labelsValue.backToWeek)}</button>
            </div>
            <div class="grid grid-cols-7 gap-0.5 mb-1 text-center text-[11px] text-ink-faint" aria-hidden="true">${heads}</div>
            <div class="grid grid-cols-7 gap-0.5">${cells}</div>
        </div>`;
    }

    chip(when, value, label, selected) {
        return `<button type="button" data-when="${when}" data-value="${value}" data-key="${when}-${value}"
            aria-pressed="${selected}"
            class="h-7 px-3 rounded-full border text-xs transition-colors cursor-pointer
                   ${selected ? "bg-accent text-accent-ink border-accent" : "border-line text-ink-muted hover:bg-hover"}">${esc(label)}</button>`;
    }

    /** Selected, refused, or neither — the three states every cell here is in. */
    tone(selected, disabled) {
        if (selected) {
            return "bg-accent text-accent-ink border-accent font-semibold cursor-pointer";
        }
        if (disabled) {
            return "border-transparent text-ink-faint/40 cursor-default";
        }
        return "bg-surface border-line text-ink hover:bg-hover transition-colors cursor-pointer";
    }

    // ── Words and numbers ────────────────────────────────────────────────────

    /** "15:00" or "3:00 pm" — ClockFormat::time(), to the character. */
    time(at) {
        const m = ((at % DAY) + DAY) % DAY;
        const h = Math.floor(m / 60), mm = String(m % 60).padStart(2, "0");
        return this.hour12
            ? `${h % 12 || 12}:${mm} ${h < 12 ? "am" : "pm"}`
            : `${String(h).padStart(2, "0")}:${mm}`;
    }

    duration(minutes) {
        const L = this.labelsValue;
        const d = Math.floor(minutes / DAY), h = Math.floor((minutes % DAY) / 60), m = minutes % 60;
        const parts = [];
        if (d) parts.push(1 === d ? L.dayOne : L.days.replace("%count%", d));
        if (h) parts.push(L.hours.replace("%count%", h));
        if (m) parts.push(L.minutes.replace("%count%", m));
        return parts.join(" ") || L.minutes.replace("%count%", 0);
    }

    dayLabel(n) {
        const prefix = 0 === n ? this.labelsValue.today : 1 === n ? this.labelsValue.tomorrow : this.weekday(n);
        return `${prefix} ${this.format(this.dateOf(n), { day: "numeric", month: "short" })}`;
    }

    weekday(n) {
        return this.format(this.dateOf(n), { weekday: "short" });
    }

    format(date, options) {
        try {
            return new Intl.DateTimeFormat(this.lang, { ...options, timeZone: "UTC" }).format(date);
        } catch {
            return new Intl.DateTimeFormat(undefined, { ...options, timeZone: "UTC" }).format(date);
        }
    }

    language() {
        const lang = (document.documentElement.lang || "").replace("_", "-");
        try {
            return Intl.DateTimeFormat.supportedLocalesOf(lang).length ? lang : lang.split("-")[0] || undefined;
        } catch {
            return lang.split(/[-_]/)[0] || undefined;
        }
    }

    // Days are counted from `today` in UTC, so they are whole and DST-proof.
    dayOf(y, m, d) {
        const [ty, tm, td] = this.todayValue.split("-").map(Number);
        return Math.round((Date.UTC(y, m - 1, d) - Date.UTC(ty, tm - 1, td)) / 86400000);
    }

    dateOf(n) {
        const [ty, tm, td] = this.todayValue.split("-").map(Number);
        return new Date(Date.UTC(ty, tm - 1, td + n));
    }
}

function esc(text) {
    return String(text).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
}
