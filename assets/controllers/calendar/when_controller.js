import { Controller } from "@hotwired/stimulus";
import { prefersHour12 } from "../../clock_format.js";

/**
 * plMail's date-and-time picker.
 *
 * One or two cards say what is chosen; one panel under them sets it: a strip of
 * six days (a month, with arrows, behind the calendar button), then the hour,
 * then the minute (every five minutes and an exact field behind "···").
 *
 * THREE SHAPES, from which targets and `mode` are present
 * ───────────────────────────────────────────────────────
 * - start AND end, with days — the event editor. The cards pick which side the
 *   panel sets; the start's quarter-hour moves on to the end, which also has
 *   length chips.
 * - start alone, with days — "send later". One card.
 * - `mode: "time"`, start and end — a booking page's daily hours. No days.
 *
 * THE HIDDEN INPUTS ARE THE TRUTH
 * ───────────────────────────────
 * `start` and `end` are the form's own inputs, carrying the value shape they
 * always did ("2026-09-25T15:00", or "15:00" in time mode), and whatever owned
 * them still does: calendar--event-form validates them and drags the end along
 * with the start; compose--schedule seeds, bounds and confirms its field; the
 * booking form turns its pair into minutes. So this controller only WRITES them
 * (firing `input` and `change`, as typing would) and REDRAWS from them — their
 * `value` setters are wrapped on this instance, so a write from anywhere else
 * redraws too, and `focus()` lands on the card.
 *
 * BOUNDS ARE OFFERED, NOT ENFORCED AFTERWARDS
 * ───────────────────────────────────────────
 * The end must come after the start, and a field with `min` / `max` (the
 * scheduler's "not in the past, not beyond the hold") must stay inside them.
 * Choices outside are disabled, and a pick that would land outside — an hour
 * whose current minute is too early — is moved to the nearest time inside.
 * Whatever owned the field still refuses a bad value on its own terms.
 *
 * Times are minutes from `today`'s midnight, so a day is a multiple of 1440 and
 * every bound is one comparison. Days are counted in UTC, never through a local
 * Date's hours, so a DST change cannot make a day 23 hours long.
 */
const DAY = 1440;

export default class extends Controller {
    static targets = ["start", "end", "startCard", "endCard", "panel"];
    static values = { today: String, labels: Object, mode: { type: String, default: "datetime" } };

    connect() {
        this.hour12 = prefersHour12()
            ?? true === new Intl.DateTimeFormat(undefined, { hour: "numeric" }).resolvedOptions().hour12;
        this.lang = this.language();
        this.timeOnly = "time" === this.modeValue;
        this.side = "start";
        this.month = false;
        this.view = null;
        this.fine = false;

        this.native = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value");
        for (const input of this.inputs()) {
            const self = this;
            Object.defineProperty(input, "value", {
                configurable: true,
                get() { return self.native.get.call(this); },
                set(v) { self.native.set.call(this, v); self.render(); },
            });
            // Whoever owns the field focuses it — on an error, on opening. A
            // hidden input cannot take focus; its card can.
            input.focus = () => this.cardFor(input)?.focus();
        }

        this.observer = new MutationObserver(() => this.render());
        for (const input of this.inputs()) {
            this.observer.observe(input, { attributes: true, attributeFilter: ["aria-invalid", "min", "max"] });
        }

        this.allDayBox = this.timeOnly ? null : this.element.closest("form")?.querySelector('input[name="isAllDay"]') ?? null;
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
        for (const input of this.inputs()) {
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

    // ── The inputs ───────────────────────────────────────────────────────────

    inputs() {
        return this.hasEndTarget ? [this.startTarget, this.endTarget] : [this.startTarget];
    }

    editing() {
        return "end" === this.side && this.hasEndTarget ? this.endTarget : this.startTarget;
    }

    cardFor(input) {
        if (input === this.startTarget) {
            return this.hasStartCardTarget ? this.startCardTarget : null;
        }
        return this.hasEndCardTarget ? this.endCardTarget : null;
    }

    parse(text) {
        const s = String(text ?? "");
        if (this.timeOnly) {
            const m = s.match(/^(\d{2}):(\d{2})/);
            return m && +m[1] <= 24 && +m[2] < 60 ? +m[1] * 60 + (+m[2]) : null;
        }
        const m = s.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
        return m ? this.dayOf(+m[1], +m[2], +m[3]) * DAY + (+m[4]) * 60 + (+m[5]) : null;
    }

    read(input) {
        return this.parse(this.native.get.call(input));
    }

    write(input, at) {
        const pad = (n) => String(n).padStart(2, "0");
        const minutes = ((at % DAY) + DAY) % DAY;
        const clock = `${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}`;
        let value = clock;
        if (!this.timeOnly) {
            const d = this.dateOf(Math.floor(at / DAY));
            value = `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}T${clock}`;
        }

        if (value === this.native.get.call(input)) {
            return;
        }
        this.native.set.call(input, value);
        input.dispatchEvent(new Event("input", { bubbles: true }));
        input.dispatchEvent(new Event("change", { bubbles: true }));
        this.render();
    }

    // ── Bounds ───────────────────────────────────────────────────────────────

    /** What the side being set may be: after the start, inside min and max. */
    bounds() {
        const input = this.editing();
        let lo = null;
        if (this.hasEndTarget && input === this.endTarget) {
            const start = this.read(this.startTarget);
            if (null !== start) lo = { at: start, exclusive: true };
        } else if (input.getAttribute("min")) {
            const min = this.parse(input.getAttribute("min"));
            if (null !== min) lo = { at: min, exclusive: false };
        }
        const max = input.getAttribute("max") ? this.parse(input.getAttribute("max")) : null;
        return { lo, hi: null === max ? null : max };
    }

    /** Whether any minute from `a` to `b` is allowed. */
    open(a, b, { lo, hi }) {
        const aboveLo = null === lo || (lo.exclusive ? b > lo.at : b >= lo.at);
        const belowHi = null === hi || a <= hi;
        return aboveLo && belowHi;
    }

    /** The nearest allowed time to `at`. */
    clamp(at, { lo, hi }) {
        if (null !== lo && (lo.exclusive ? at <= lo.at : at < lo.at)) {
            // After a start: a quarter past it. After "now": the next five minutes.
            at = lo.exclusive ? lo.at + 15 : Math.ceil(lo.at / 5) * 5;
        }
        if (null !== hi && at > hi) {
            at = hi;
        }
        return at;
    }

    put(at) {
        this.write(this.editing(), this.clamp(at, this.bounds()));
    }

    // ── The panel's buttons ──────────────────────────────────────────────────

    pick(event) {
        const button = event.target.closest("button[data-when]");
        if (!button || button.disabled) {
            return;
        }
        const { when, value } = button.dataset;
        const n = Number(value);
        const at = this.read(this.editing()) ?? 0;
        const day = Math.floor(at / DAY);
        const hh = Math.floor((at % DAY) / 60);
        const mm = at % 60;
        const quarterRow = !this.fine && mm % 15 === 0;

        this.focusKey = button.dataset.key;

        switch (when) {
            case "day":
                this.put(n * DAY + (at % DAY));
                this.month = false;
                break;
            case "month":
                this.month = !this.month;
                this.view = this.firstOfMonth(day);
                break;
            case "shift":
                this.view = this.firstOfMonth(this.view ?? day, n);
                break;
            case "hour":
                this.put(day * DAY + n * 60 + mm);
                break;
            case "half":
                if ((hh >= 12) !== (1 === n)) {
                    this.put(day * DAY + (hh + (1 === n ? 12 : -12)) * 60 + mm);
                }
                break;
            case "minute":
                this.put(day * DAY + hh * 60 + n);
                // From the quarter-hour row a start is done: on to the end, and
                // the keyboard with it. From the fine grid the reader is still
                // adjusting, so it stays.
                if (quarterRow && this.hasEndTarget && "start" === this.side) {
                    this.side = "end";
                    this.focusKey = null;
                    this.render();
                    this.endCardTarget.focus();
                    return;
                }
                break;
            case "fine":
                if (1 === n) {
                    this.fine = true;
                } else {
                    // Back to quarter hours: the minute goes to the nearest one,
                    // or the fine grid would reopen at once around a :05.
                    this.fine = false;
                    this.put(day * DAY + hh * 60 + Math.round(mm / 15) * 15);
                }
                break;
            case "length": {
                const start = this.read(this.startTarget);
                if (null !== start) {
                    this.write(this.endTarget, start + n);
                }
                break;
            }
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
        const at = this.read(this.editing()) ?? 0;
        this.focusKey = "exact";
        this.put(Math.floor(at / 60) * 60 + v);
    }

    // ── Drawing ──────────────────────────────────────────────────────────────

    render() {
        if (!this.hasPanelTarget) {
            return;
        }
        const L = this.labelsValue;
        const start = this.read(this.startTarget);
        const end = this.hasEndTarget ? this.read(this.endTarget) : null;
        const allDay = true === this.allDayBox?.checked;
        const editingEnd = "end" === this.side && this.hasEndTarget;

        if (this.hasStartCardTarget) {
            this.card(this.startCardTarget, !editingEnd, this.startTarget, start, null, allDay);
        }
        if (this.hasEndCardTarget) {
            this.card(this.endCardTarget, editingEnd, this.endTarget, end, start, allDay);
        }

        const at = editingEnd ? end : start;
        if (null === at) {
            this.panelTarget.replaceChildren();
            return;
        }

        const bounds = this.bounds();
        const day = Math.floor(at / DAY);
        const hh = Math.floor((at % DAY) / 60);
        const mm = at % 60;
        const parts = [];

        if (editingEnd && !allDay && !this.timeOnly && null !== start) {
            const len = end - start;
            parts.push(`<div class="flex flex-wrap gap-1.5" role="group" aria-label="${esc(L.length)}">`
                + [30, 60, 90, 120, 180].map((l) => this.chip("length", l, this.duration(l), l === len)).join("")
                + "</div>");
        }

        if (!this.timeOnly) {
            parts.push(this.month
                ? this.monthGrid(day, bounds)
                : this.strip(day, bounds, editingEnd ? Math.floor(start / DAY) : null));
        }

        if (!allDay) {
            parts.push(this.hourGrid(day, hh, bounds));
            parts.push(this.minuteRow(day, hh, mm, bounds));
        }

        this.panelTarget.innerHTML = parts.join("");

        if (this.focusKey) {
            this.panelTarget.querySelector(`[data-key="${this.focusKey}"]`)?.focus();
            this.focusKey = null;
        }
    }

    card(card, on, input, at, start, allDay) {
        const invalid = "true" === input.getAttribute("aria-invalid");
        const choosable = this.hasEndTarget;

        if (choosable) {
            card.setAttribute("aria-pressed", on ? "true" : "false");
        }
        const lit = choosable && on;
        card.classList.toggle("border-accent", lit && !invalid);
        card.classList.toggle("ring-2", lit || invalid);
        card.classList.toggle("ring-accent/20", lit && !invalid);
        card.classList.toggle("bg-surface", lit || !choosable);
        card.classList.toggle("bg-sunken", choosable && !on);
        card.classList.toggle("border-line", !lit && !invalid);
        card.classList.toggle("border-danger", invalid);
        card.classList.toggle("ring-danger/30", invalid);

        const dayPart = card.querySelector('[data-when-part="day"]');
        const timePart = card.querySelector('[data-when-part="time"]');
        const lengthPart = card.querySelector('[data-when-part="length"]');

        if (null === at) {
            if (dayPart) dayPart.textContent = "";
            timePart.textContent = "—";
            return;
        }

        if (dayPart) {
            const day = Math.floor(at / DAY);
            dayPart.textContent = null !== start && day === Math.floor(start / DAY)
                ? this.labelsValue.sameDay
                : this.dayLabel(day);
        }
        timePart.textContent = allDay ? "" : this.time(at);
        timePart.hidden = allDay;

        if (lengthPart) {
            lengthPart.textContent = null !== start && !allDay && at > start ? this.duration(at - start) : "";
        }
    }

    strip(day, bounds, startDay) {
        const L = this.labelsValue;
        // Six days from today, or around the chosen day when it is further out.
        const anchor = day > 5 || day < 0 ? day - 2 : 0;
        let html = "";
        for (let n = anchor; n < anchor + 6; n++) {
            const off = !this.open(n * DAY, n * DAY + DAY - 1, bounds);
            // "Today" does not fit a narrow strip — a phone, or the send-later
            // menu — so there it is the weekday like the rest, marked by the
            // ring instead. Asked of the strip's own width, not the window's.
            const label = null !== startDay && n === startDay ? esc(L.sameShort)
                : 0 === n ? `<span class="hidden @[22rem]:inline">${esc(L.today)}</span><span class="@[22rem]:hidden">${esc(this.weekday(n))}</span>`
                : esc(this.weekday(n));
            const todayRing = 0 === n && n !== day ? "ring-1 ring-accent/40" : "";
            html += `<button type="button" data-when="day" data-value="${n}" data-key="day-${n}"
                aria-pressed="${n === day}" ${off ? "disabled" : ""}
                aria-label="${esc(this.dayLabel(n))}"
                class="flex-1 min-w-0 h-12 flex flex-col items-center justify-center gap-0.5 rounded-xl border text-ink
                       ${this.tone(n === day, off)} ${todayRing}">
                <span class="text-[11px] truncate max-w-full px-0.5">${label}</span>
                <span class="text-[15px] font-semibold tabular-nums">${this.dateOf(n).getUTCDate()}</span>
            </button>`;
        }
        html += `<button type="button" data-when="month" data-key="month" aria-label="${esc(L.anotherDay)}"
            class="w-11 shrink-0 h-12 flex items-center justify-center rounded-xl border border-dashed border-line
                   text-ink-muted hover:bg-hover transition-colors cursor-pointer">
            <i class="fa-regular fa-calendar" aria-hidden="true"></i>
        </button>`;
        return `<div class="@container"><div class="flex gap-1.5">${html}</div></div>`;
    }

    monthGrid(day, bounds) {
        const L = this.labelsValue;
        const first = this.view ?? this.firstOfMonth(day);
        const view = this.dateOf(first);
        const gridStart = first - ((view.getUTCDay() + 6) % 7);
        const title = this.format(view, { month: "long", year: "numeric" });

        let heads = "";
        for (let i = 0; i < 7; i++) {
            heads += `<span>${esc(this.format(this.dateOf(gridStart + i), { weekday: "narrow" }))}</span>`;
        }
        let cells = "";
        for (let i = 0; i < 42; i++) {
            const n = gridStart + i;
            const d = this.dateOf(n);
            const sel = n === day;
            const off = !this.open(n * DAY, n * DAY + DAY - 1, bounds);
            const inMonth = d.getUTCMonth() === view.getUTCMonth();
            cells += `<button type="button" data-when="day" data-value="${n}" data-key="mday-${n}"
                aria-pressed="${sel}" ${off ? "disabled" : ""} aria-label="${esc(this.dayLabel(n))}"
                class="h-8 rounded-full text-[13px] tabular-nums transition-colors
                       ${sel ? "bg-accent text-accent-ink font-semibold"
                           : off ? "text-ink-faint/40 cursor-default"
                           : `${0 === n ? "bg-accent/15 font-semibold" : ""} ${inMonth ? "text-ink" : "text-ink-faint"} hover:bg-hover cursor-pointer`}">${d.getUTCDate()}</button>`;
        }
        // No month before the one the lower bound falls in, and none after the upper.
        const prevOff = null !== bounds.lo && first <= this.firstOfMonth(Math.floor(bounds.lo.at / DAY));
        const nextOff = null !== bounds.hi && first >= this.firstOfMonth(Math.floor(bounds.hi / DAY));
        const arrow = (k, icon, label, off) => `<button type="button" data-when="shift" data-value="${k}" data-key="shift-${k}"
            aria-label="${esc(label)}" ${off ? "disabled" : ""}
            class="w-8 h-8 shrink-0 flex items-center justify-center rounded-lg transition-colors
                   ${off ? "text-ink-faint/40 cursor-default" : "text-ink-muted hover:bg-hover cursor-pointer"}">
            <i class="fa-solid ${icon} text-xs" aria-hidden="true"></i></button>`;

        return `<div>
            <div class="flex items-center gap-1 mb-1.5">
                ${arrow(-1, "fa-chevron-left", L.previousMonth, prevOff)}
                <span class="flex-1 text-center text-sm font-semibold text-ink" aria-live="polite">${esc(title)}</span>
                ${arrow(1, "fa-chevron-right", L.nextMonth, nextOff)}
                <button type="button" data-when="month" data-key="month-close"
                    class="ml-1 h-7 px-2.5 rounded-lg text-xs text-ink-muted hover:bg-hover transition-colors cursor-pointer">${esc(L.backToWeek)}</button>
            </div>
            <div class="grid grid-cols-7 gap-0.5 mb-1 text-center text-[11px] text-ink-faint" aria-hidden="true">${heads}</div>
            <div class="grid grid-cols-7 gap-0.5">${cells}</div>
        </div>`;
    }

    hourGrid(day, hh, bounds) {
        const off = (h) => !this.open(day * DAY + h * 60, day * DAY + h * 60 + 59, bounds);
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
        const halfOff = (isPm) => !this.open(day * DAY + (isPm ? 720 : 0), day * DAY + (isPm ? 1439 : 719), bounds);
        const half = (isPm, label) => {
            const sel = pm === isPm, disabled = !sel && halfOff(isPm);
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

    minuteRow(day, hh, mm, bounds) {
        const L = this.labelsValue;
        const button = (m) => {
            const at = day * DAY + hh * 60 + m;
            const off = !this.open(at, at, bounds);
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
        const id = `${this.startTarget.id || "when"}-exact-minute`;
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
        const m = this.timeOnly && DAY === at ? DAY : ((at % DAY) + DAY) % DAY;
        const h = Math.floor(m / 60), mm = String(m % 60).padStart(2, "0");
        if (!this.hour12) {
            return `${String(h).padStart(2, "0")}:${mm}`;
        }
        return `${h % 12 || 12}:${mm} ${h < 12 || 24 === h ? "am" : "pm"}`;
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
        const [ty, tm, td] = this.todayParts();
        return Math.round((Date.UTC(y, m - 1, d) - Date.UTC(ty, tm - 1, td)) / 86400000);
    }

    dateOf(n) {
        const [ty, tm, td] = this.todayParts();
        return new Date(Date.UTC(ty, tm - 1, td + n));
    }

    /** The first of the month `n` is in, `shift` months on. */
    firstOfMonth(n, shift = 0) {
        const d = this.dateOf(n);
        const first = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + shift, 1));
        return this.dayOf(first.getUTCFullYear(), first.getUTCMonth() + 1, 1);
    }

    todayParts() {
        const parts = (this.todayValue || "").split("-").map(Number);
        if (3 === parts.length && parts.every((p) => p > 0)) {
            return parts;
        }
        const now = new Date();
        return [now.getFullYear(), now.getMonth() + 1, now.getDate()];
    }
}

function esc(text) {
    return String(text).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
}
