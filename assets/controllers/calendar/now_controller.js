import { Controller } from "@hotwired/stimulus";
import * as Turbo from "@hotwired/turbo";

/**
 * Keeps "now" true on a calendar that stays open (#253).
 *
 * The red line in the day and week grids, and the circle on today's date in the
 * month, were drawn at the minute the page was built and never moved. A
 * calendar left open beside the mail, which is the docked pane's whole job, had
 * the line an hour behind by lunch and the circle on yesterday by breakfast.
 *
 * **Within the day, the line moves.** Once a minute, on the minute, each `line`
 * target's `top` is set to the minute of the day as a percentage, the same
 * spelling the server wrote it in. Nothing else on the page changes, so this is
 * one style write a minute, not a re-render.
 *
 * **When the date changes, the view is drawn again.** A new day moves the line
 * to another column, the tint to another day, and the circle to another date,
 * and only the server knows how each view draws them; working that out here
 * would be a second copy of three templates. So the view reloads: its Turbo
 * Frame when it is in one (the pane), the page otherwise. Only a view that
 * shows the old day or the new one reloads; a week three weeks ahead has nothing
 * that moves at midnight.
 *
 * Both are read on the VIEW's clock, `zone`, never the browser's: the grid is
 * drawn in the calendar's zone, and a reader whose laptop is in another one
 * would otherwise see the line at their own hour on a grid that is not on it.
 *
 * A tab in the background gets no timers worth trusting, so coming back to it
 * checks at once rather than at the next minute.
 */
export default class extends Controller {
    static targets = ["line"];
    static values = { zone: String, today: String };

    connect() {
        this.format = new Intl.DateTimeFormat("en-CA", {
            timeZone: this.zoneValue || undefined,
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
            hourCycle: "h23",
        });
        this.onVisible = () => {
            if ("visible" === document.visibilityState) {
                this.tick();
            }
        };
        document.addEventListener("visibilitychange", this.onVisible);

        this.tick();
        this.schedule();
    }

    disconnect() {
        clearTimeout(this.timer);
        document.removeEventListener("visibilitychange", this.onVisible);
    }

    // On the minute rather than every 60s from whenever the page loaded, so the
    // line steps with the clock in the corner of the screen and not up to a
    // minute after it.
    schedule() {
        const wait = 60_000 - (Date.now() % 60_000) + 50;

        this.timer = setTimeout(() => {
            this.tick();
            this.schedule();
        }, wait);
    }

    tick() {
        const parts = Object.fromEntries(this.format.formatToParts(new Date()).map((part) => [part.type, part.value]));
        const dayKey = `${parts.year}-${parts.month}-${parts.day}`;

        if (dayKey !== this.todayValue) {
            this.dayChanged(dayKey);

            return;
        }

        const minute = Number(parts.hour) * 60 + Number(parts.minute);

        this.lineTargets.forEach((line) => {
            line.style.top = `${((minute * 100) / 1440).toFixed(4)}%`;
        });
    }

    dayChanged(dayKey) {
        const shows = (key) => null !== this.element.querySelector(`[data-day="${key}"]`);

        if (this.reloading || (false === shows(this.todayValue) && false === shows(dayKey))) {
            this.todayValue = dayKey;

            return;
        }

        this.reloading = true;

        const frame = this.element.closest("turbo-frame[src]");

        if (null !== frame) {
            frame.reload();

            return;
        }

        Turbo.visit(window.location.href, { action: "replace" });
    }
}
