import { Controller } from "@hotwired/stimulus";
import { prefersHour12 } from "../../clock_format.js";

/**
 * A date-and-time (or time-only) field that writes the time on the USER's clock.
 *
 * WHY NOT THE NATIVE INPUT
 * ────────────────────────
 * `<input type="datetime-local">` and `type="time"` draw their time in the
 * BROWSER's locale, and nothing a page can set changes that — not `lang`, not
 * `step`, not a pattern. A person who picked the 24-hour clock in Settings, on a
 * browser set to US English, was shown "03:00 PM" in the one place in plMail
 * where they type a time. Every other time in the app follows the setting.
 *
 * WHAT THIS DOES
 * ──────────────
 * Put the controller on the native input. That input stays exactly what it was
 * to everything else — its name, its value in the ISO shape it always had
 * ("2026-09-25T15:00" or "15:00"), its id, its targets and actions — and becomes
 * `type="hidden"`. In its place the user sees a native DATE field (dates were
 * never the problem) and a text field for the time, printed "15:00" or
 * "3:00 pm" by the setting and read back liberally: "15", "1530", "3pm",
 * "3:30 p", "15.30". Arrow keys step it by the input's `step` (15 minutes by
 * default).
 *
 * Other controllers keep working without knowing any of this:
 * - an edit in the visible fields writes the hidden one and fires `input` and
 *   `change` on it, so its listeners hear what they always heard;
 * - a script that WRITES the hidden one (the editor dragging the end along with
 *   the start, the scheduler filling a first suggestion) is seen, because this
 *   instance's `value` setter is wrapped to redraw the visible fields;
 * - `focus()` on it focuses the visible field, and `aria-invalid` set on it is
 *   mirrored onto the visible fields, so an error still lands where it is seen.
 *
 * The `<label for>` that pointed at the input is pointed at the first visible
 * field instead, so clicking the label and reading it aloud both still work.
 */
export default class extends Controller {
    connect() {
        const bearer = this.element;

        // The setting as stamped on <html> — see clock_format.js. Unstamped
        // (a page with nobody signed in) falls back to the browser's own habit.
        this.hour12 = prefersHour12()
            ?? true === new Intl.DateTimeFormat(undefined, { hour: "numeric" }).resolvedOptions().hour12;

        this.mode = "time" === bearer.type ? "time" : "datetime";
        this.native = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value");
        this.originalType = bearer.type;

        this.wrapper = document.createElement("span");
        this.wrapper.className = `${bearer.className} flex items-center gap-2 focus-within:ring-2 focus-within:ring-accent`;
        this.wrapper.dataset.datetimeField = this.mode;

        const inner = "min-w-0 bg-transparent border-0 p-0 text-sm text-ink focus:outline-none focus:ring-0";

        if ("datetime" === this.mode) {
            this.dateInput = document.createElement("input");
            this.dateInput.type = "date";
            this.dateInput.className = `${inner} flex-1`;
            this.dateInput.addEventListener("change", () => this.commit());
            this.wrapper.append(this.dateInput);
        }

        this.timeInput = document.createElement("input");
        this.timeInput.type = "text";
        this.timeInput.inputMode = "text";
        this.timeInput.autocomplete = "off";
        this.timeInput.spellcheck = false;
        this.timeInput.className = `${inner} ${"datetime" === this.mode ? "w-[4.75rem] shrink-0 text-right" : "flex-1"} tabular-nums`;
        this.timeInput.placeholder = this.hour12 ? "3:00 pm" : "15:00";
        this.timeInput.addEventListener("change", () => this.commit());
        this.timeInput.addEventListener("keydown", (event) => this.onKey(event));
        this.wrapper.append(this.timeInput);

        // The first visible field answers to the label, and both carry what the
        // label and the error lines said about the original.
        const first = this.dateInput ?? this.timeInput;
        let labelText = bearer.getAttribute("aria-label") || "";
        if (bearer.id) {
            first.id = `${bearer.id}-field`;
            document.querySelectorAll(`label[for="${CSS.escape(bearer.id)}"]`).forEach((label) => {
                label.htmlFor = first.id;
                labelText ||= label.textContent.trim();
            });
        }
        for (const attr of ["aria-describedby", "required"]) {
            if (bearer.hasAttribute(attr)) {
                [this.dateInput, this.timeInput].filter(Boolean).forEach((el) => el.setAttribute(attr, bearer.getAttribute(attr)));
            }
        }
        if (labelText && this.dateInput) {
            // "Starts" and "Starts, time": two fields under one heading, told
            // apart by a word the template translated (`data-datetime-time-label`).
            const part = bearer.dataset.datetimeTimeLabel;
            this.dateInput.setAttribute("aria-label", labelText);
            this.timeInput.setAttribute("aria-label", part ? `${labelText}, ${part}` : labelText);
        } else if (labelText && bearer.hasAttribute("aria-label")) {
            this.timeInput.setAttribute("aria-label", labelText);
        }

        bearer.type = "hidden";
        bearer.after(this.wrapper);

        const self = this;
        Object.defineProperty(bearer, "value", {
            configurable: true,
            get() {
                return self.native.get.call(this);
            },
            set(v) {
                self.native.set.call(this, v);
                self.render();
            },
        });
        bearer.focus = (options) => (this.dateInput && !this.dateInput.value ? this.dateInput : this.timeInput).focus(options);

        this.observer = new MutationObserver(() => this.mirror());
        this.observer.observe(bearer, { attributes: true, attributeFilter: ["aria-invalid", "min", "max", "disabled"] });

        this.render();
        this.mirror();
    }

    disconnect() {
        this.observer?.disconnect();
        this.wrapper?.remove();
        if (this.element.isConnected) {
            delete this.element.value;
            delete this.element.focus;
            this.element.type = this.originalType;
        }
    }

    // ── Reading and writing ────────────────────────────────────────────────

    /** Visible fields → the hidden value, then tell whoever is listening. */
    commit() {
        const minutes = this.parse(this.timeInput.value);
        let value = "";

        if (null !== minutes) {
            const hhmm = `${String(Math.floor(minutes / 60)).padStart(2, "0")}:${String(minutes % 60).padStart(2, "0")}`;
            value = "time" === this.mode ? hhmm : this.dateInput.value ? `${this.dateInput.value}T${hhmm}` : "";
            this.timeInput.value = this.format(minutes);
            this.timeInput.removeAttribute("aria-invalid");
        } else if ("" !== this.timeInput.value.trim()) {
            this.timeInput.setAttribute("aria-invalid", "true");
        }

        if (value === this.native.get.call(this.element)) {
            return;
        }

        this.native.set.call(this.element, value);
        this.element.dispatchEvent(new Event("input", { bubbles: true }));
        this.element.dispatchEvent(new Event("change", { bubbles: true }));
    }

    /** The hidden value → the visible fields. */
    render() {
        const value = this.native.get.call(this.element) || "";
        const [datePart, timePart] = "time" === this.mode ? ["", value] : value.split("T");

        if (this.dateInput) {
            this.dateInput.value = datePart || "";
        }

        const minutes = this.parse(timePart || "", true);
        this.timeInput.value = null === minutes ? "" : this.format(minutes);
    }

    mirror() {
        const bearer = this.element;
        const invalid = "true" === bearer.getAttribute("aria-invalid");

        [this.dateInput, this.timeInput].filter(Boolean).forEach((el) => {
            invalid ? el.setAttribute("aria-invalid", "true") : el.removeAttribute("aria-invalid");
            el.disabled = bearer.disabled;
        });
        this.wrapper.classList.toggle("ring-2", invalid);
        this.wrapper.classList.toggle("ring-danger", invalid);

        if (this.dateInput) {
            // "2026-09-25T15:00" → "2026-09-25": the date field takes the day.
            this.dateInput.min = (bearer.getAttribute("min") || "").split("T")[0];
            this.dateInput.max = (bearer.getAttribute("max") || "").split("T")[0];
        }
    }

    // ── The time field ──────────────────────────────────────────────────────

    onKey(event) {
        if ("Enter" === event.key) {
            this.commit();
            return;
        }
        if ("ArrowUp" !== event.key && "ArrowDown" !== event.key) {
            return;
        }
        event.preventDefault();

        const stepSeconds = parseInt(this.element.getAttribute("step") || "", 10);
        const step = stepSeconds > 0 ? Math.max(1, Math.round(stepSeconds / 60)) : 15;
        const now = this.parse(this.timeInput.value) ?? 0;
        const snapped = "ArrowUp" === event.key
            ? Math.floor(now / step) * step + step
            : Math.ceil(now / step) * step - step;

        this.timeInput.value = this.format(((snapped % 1440) + 1440) % 1440);
        this.commit();
    }

    /**
     * Minutes after midnight, or null. `strict` for the hidden value, which is
     * always "HH:MM"; everything else is what a person might type.
     */
    parse(text, strict = false) {
        const raw = String(text).trim().toLowerCase();
        if ("" === raw) {
            return null;
        }

        if (strict) {
            // Anything past 23:59 — a booking day may END at "24:00" — is shown
            // empty, as the native field it replaces showed it.
            const m = raw.match(/^(\d{2}):(\d{2})/);
            return m && Number(m[1]) < 24 && Number(m[2]) < 60 ? Number(m[1]) * 60 + Number(m[2]) : null;
        }

        const m = raw.match(/^(\d{1,2})(?:[:.h]?(\d{2}))?\s*(a|p|am|pm|a\.m\.|p\.m\.)?$/);
        if (!m) {
            return null;
        }

        let hour = Number(m[1]);
        const minute = m[2] ? Number(m[2]) : 0;
        const meridiem = m[3] ? m[3][0] : null;

        if (minute > 59) {
            return null;
        }
        if (meridiem) {
            if (hour < 1 || hour > 12) {
                return null;
            }
            hour = (hour % 12) + ("p" === meridiem ? 12 : 0);
        } else if (hour > 23) {
            return null;
        }

        return hour * 60 + minute;
    }

    /** "15:00" or "3:00 pm" — ClockFormat::time(), to the character. */
    format(minutes) {
        const hour = Math.floor(minutes / 60);
        const minute = String(minutes % 60).padStart(2, "0");

        return this.hour12
            ? `${hour % 12 || 12}:${minute} ${hour < 12 ? "am" : "pm"}`
            : `${String(hour).padStart(2, "0")}:${minute}`;
    }
}
