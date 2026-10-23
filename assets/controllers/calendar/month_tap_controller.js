import { Controller } from "@hotwired/stimulus";

/**
 * What a tap on a month day does, which depends on how wide the month is (#253).
 *
 * **On a narrow month it chooses the day the list under the grid shows, and
 * that is all it does.** Below 39rem the days are bars and the month view lists
 * one day in full beneath them (see _view_month). Any tap or click on a day
 * selects it, entry bars and the date included: a bar is too small to aim at,
 * and opening one blind would be a guess. The day does not open over its
 * neighbours either; the list is where it is read, and where its entries open
 * and a new one is added.
 *
 * **On a wider month, and only on a screen with no pointer, it opens the day.**
 * With a mouse a day opens under the pointer: its card widens over the days
 * around it and shows every entry in full (see _month_shell, `day-open:`). A
 * phone has no hover, so there the first tap anywhere on a day opens it, by
 * putting `data-open` on it, and does nothing else: not the entry under the
 * finger, not the date's link to the day view. Those act on the second tap,
 * once the card is open and the reader can see what they are tapping. A tap
 * outside the open day, or Escape, closes it.
 *
 * `(hover: none)` rather than `pointerType`, for the same reason Tailwind's
 * own `hover:` asks it: what matters is whether this screen CAN hover, and a
 * laptop with a touch screen that can is better served by its pointer.
 *
 * In the capture phase, so the tap is swallowed before the entry's own
 * handlers or Turbo's link handling see it. Which arrangement is showing is
 * read off the list itself, `hidden` until the container query shows it, so
 * the breakpoint is written once, in the template.
 */
export default class extends Controller {
    static targets = ["list"];
    static values = { selected: String };

    connect() {
        this.touch = window.matchMedia("(hover: none)");
        this.mark(this.selectedValue);
    }

    tap(event) {
        const day = event.target.closest("[data-calendar-grid] [data-day]");

        if (null === day) {
            return;
        }

        if (this.narrow()) {
            event.preventDefault();
            event.stopPropagation();
            this.select(day.dataset.day);

            return;
        }

        if (false === this.touch.matches || day.hasAttribute("data-open")) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        this.close();
        day.setAttribute("data-open", "");
    }

    select(dayKey) {
        this.selectedValue = dayKey;
        this.mark(dayKey);

        // The toolbar's + adds to the chosen day too. Two + buttons a few
        // rows apart, one starting on the day the reader picked and one on
        // today, was a trap: it is the toolbar's that is reached first.
        document.querySelectorAll("[data-calendar-new-event]").forEach((button) => {
            button.setAttribute(
                "data-ui--modal-src-value",
                `${button.dataset.calendarNewEvent}?start=${encodeURIComponent(dayKey)}`,
            );
        });

        this.listTargets.forEach((list) => {
            list.hidden = list.dataset.listDay !== dayKey;
        });
    }

    outside(event) {
        if (this.element.contains(event.target)) {
            return;
        }

        this.close();
    }

    close() {
        this.element.querySelectorAll("[data-day][data-open]").forEach((day) => day.removeAttribute("data-open"));
    }

    narrow() {
        const list = this.listTargets[0]?.parentElement;

        return undefined !== list && null !== list.offsetParent;
    }

    mark(dayKey) {
        this.element.querySelectorAll("[data-calendar-grid] [data-day]").forEach((day) => {
            day.toggleAttribute("data-selected", day.dataset.day === dayKey);
        });
    }
}
