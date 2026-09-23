import { Controller } from "@hotwired/stimulus";

/**
 * Keep a server-rendered clock current.
 *
 * The server prints the first reading, in the user's zone and clock format, so
 * the time is right on first paint and never blinks in. This only advances it:
 * on the next minute boundary, then every minute after, and at once when the
 * tab comes back from the background — a timer in a hidden tab is throttled,
 * and a clock that is five minutes behind until the next tick is worse than
 * none.
 *
 * TARGETS, each optional, and each printed the way the server prints it
 * ─────────────────────────────────────────────────────────────────────
 *   time      "2:05 pm" / "14:05"   — ClockFormat::time()
 *   bare      "2:05"    / "14:05"   — the time without its meridiem
 *   meridiem  "pm"      / ""        — for a layout that sets it smaller
 *   date      any date text          — rewritten only when the DAY changes
 *
 * The time is built from numbers rather than by an Intl pattern, because the
 * server's is PHP's `g:i a` and no locale's Intl output is guaranteed to match
 * it character for character; a clock whose "pm" turns into "PM" on the first
 * tick looks broken. Dates are the reverse: they are words, in the page's
 * language, so they come from Intl — and are therefore written only when the
 * day actually changes, so a small difference from the server's ICU never
 * shows as a flicker on the first tick.
 *
 * Each date target carries its own Intl options as JSON in `data-clock-date`,
 * so one controller serves "Wednesday 23 September" and "Wed" alike.
 */
export default class extends Controller {
    static targets = ["time", "bare", "meridiem", "date"];
    static values = { zone: String, hour12: Boolean, day: String };

    connect() {
        this.onVisible = () => {
            if ("visible" === document.visibilityState) {
                this.tick();
            }
        };
        document.addEventListener("visibilitychange", this.onVisible);
        this.schedule();
    }

    disconnect() {
        clearTimeout(this.timer);
        document.removeEventListener("visibilitychange", this.onVisible);
    }

    schedule() {
        clearTimeout(this.timer);
        const now = Date.now();
        // A beat past the boundary, so the reading is never the minute before.
        this.timer = setTimeout(() => {
            this.tick();
            this.schedule();
        }, 60000 - (now % 60000) + 50);
    }

    tick() {
        const now = new Date();
        const parts = this.parts(now);
        const hour = Number(parts.hour) % 24;
        const minute = parts.minute.padStart(2, "0");

        let bare;
        let meridiem = "";
        if (this.hour12Value) {
            bare = `${hour % 12 || 12}:${minute}`;
            meridiem = hour < 12 ? "am" : "pm";
        } else {
            bare = `${String(hour).padStart(2, "0")}:${minute}`;
        }

        this.timeTargets.forEach((el) => (el.textContent = meridiem ? `${bare} ${meridiem}` : bare));
        this.bareTargets.forEach((el) => (el.textContent = bare));
        this.meridiemTargets.forEach((el) => (el.textContent = meridiem));

        const day = `${parts.year}-${parts.month}-${parts.day}`;
        if (day !== this.dayValue) {
            this.dayValue = day;
            const lang = document.documentElement.lang || undefined;
            this.dateTargets.forEach((el) => {
                const options = JSON.parse(el.dataset.clockDate || "{}");
                try {
                    el.textContent = now.toLocaleDateString(lang, { ...options, timeZone: this.zone() });
                } catch {
                    el.textContent = now.toLocaleDateString(undefined, { ...options, timeZone: this.zone() });
                }
            });
        }
    }

    parts(now) {
        const fmt = new Intl.DateTimeFormat("en-CA", {
            timeZone: this.zone(),
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
            hourCycle: "h23",
        });
        return Object.fromEntries(fmt.formatToParts(now).map((p) => [p.type, p.value]));
    }

    zone() {
        return this.zoneValue || undefined;
    }
}
